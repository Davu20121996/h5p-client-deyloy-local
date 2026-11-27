# frozen_string_literal: true

# name: discourse-zalo
# about: Sign in to Discourse using Zalo Social OAuth.
# version: 0.1.0

require_relative "lib/zalo_strategy"

class ZaloAuthenticator < Auth::ManagedAuthenticator
  def name
    "zalo"
  end

  def display_name
    "Zalo"
  end

  def enabled?
    ENV.fetch("ZALO_APP_ID", "").match?(/\A[0-9]+\z/) && ENV.fetch("ZALO_APP_SECRET", "").present?
  end

  def register_middleware(omniauth)
    omniauth.provider OmniAuth::Strategies::Zalo,
                      app_id: ENV["ZALO_APP_ID"],
                      app_secret: ENV["ZALO_APP_SECRET"]
  end
end

auth_provider title_setting: "zalo_button_title", authenticator: ZaloAuthenticator.new
