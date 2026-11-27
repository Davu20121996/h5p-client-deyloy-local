# frozen_string_literal: true

require "base64"
require "digest"
require "json"
require "net/http"
require "omniauth"
require "securerandom"
require "uri"

module OmniAuth
  module Strategies
    class Zalo
      include OmniAuth::Strategy

      option :name, "zalo"
      option :app_id, nil
      option :app_secret, nil

      AUTHORIZATION_URL = "https://oauth.zaloapp.com/v4/permission"
      TOKEN_URL = "https://oauth.zaloapp.com/v4/access_token"
      PROFILE_URL = "https://graph.zalo.me/v2.0/me?fields=id,name"
      SESSION_KEY = "omniauth.zalo.pending"

      class ProviderError < StandardError; end

      uid { "#{options.app_id}:#{@profile.fetch('id')}" }
      info { { name: @profile["name"] }.compact }

      def request_phase
        verifier = SecureRandom.alphanumeric(43)
        state = SecureRandom.hex(32)
        session[SESSION_KEY] = { "state" => state, "verifier" => verifier, "expires" => Time.now.to_i + 600 }
        query = URI.encode_www_form(
          app_id: options.app_id,
          redirect_uri: "#{Discourse.base_url}/auth/zalo/callback",
          code_challenge: Base64.urlsafe_encode64(Digest::SHA256.digest(verifier), padding: false),
          state: state,
        )
        redirect("#{AUTHORIZATION_URL}?#{query}")
      end

      def callback_phase
        pending = session.delete(SESSION_KEY)
        state = request.params["state"].to_s
        unless pending && pending.fetch("expires") > Time.now.to_i &&
               Rack::Utils.secure_compare(pending.fetch("state"), state)
          return fail!(:csrf_detected)
        end

        code = request.params["code"]
        return fail!(:access_denied) unless code.is_a?(String) && !code.empty?

        token = exchange_code(code, pending.fetch("verifier"))
        @profile = get_profile(token)
        super
      rescue ProviderError
        fail!(:invalid_credentials)
      end

      private

      def exchange_code(code, verifier)
        uri = URI(TOKEN_URL)
        request = Net::HTTP::Post.new(uri, "secret_key" => options.app_secret)
        request.set_form_data(app_id: options.app_id, code: code, grant_type: "authorization_code", code_verifier: verifier)
        token = read_json(uri, request)["access_token"]
        raise ProviderError unless token.is_a?(String) && !token.empty?

        token
      end

      def get_profile(token)
        uri = URI(PROFILE_URL)
        profile = read_json(uri, Net::HTTP::Get.new(uri, "access_token" => token))
        id = profile["id"]
        raise ProviderError unless id.is_a?(String) && !id.empty?

        profile
      end

      def read_json(uri, request)
        response = Net::HTTP.start(uri.host, uri.port, use_ssl: true, open_timeout: 5, read_timeout: 10) do |http|
          http.request(request)
        end
        raise ProviderError unless response.is_a?(Net::HTTPSuccess)

        body = JSON.parse(response.body)
        raise ProviderError unless body.is_a?(Hash) && [nil, 0, "0"].include?(body["error"])

        body
      rescue JSON::ParserError, Timeout::Error, SocketError, IOError, SystemCallError, OpenSSL::SSL::SSLError,
             Net::HTTPBadResponse, Net::ProtocolError
        raise ProviderError, cause: nil
      end
    end
  end
end
