--
-- PostgreSQL database dump
--

\restrict w1fuuqLV9J1HS15IhO34ScAm8cRMiYfTdAOLwgKGotT0b795Fv0XZk68wjZo5R3

-- Dumped from database version 17.12 (Debian 17.12-1.pgdg13+1)
-- Dumped by pg_dump version 17.12 (Debian 17.12-1.pgdg13+1)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Data for Name: courses; Type: TABLE DATA; Schema: public; Owner: lti
--

COPY public.courses (id, teacher_id, title, description, created_at, updated_at, tenant_id) FROM stdin;
5	9	chăm sóc khách hàng	chăm sóc khách hàng	2026-11-14 08:41:05	2026-11-14 08:41:05	2
6	9	tư vấn phần mềm	tư vấn phần mềm	2026-11-14 08:41:36	2026-11-14 08:41:36	2
7	6	hỗ trợ pm	hỗ trợ pm	2026-11-14 08:43:37	2026-11-14 08:43:37	1
8	6	lập trình cơ bản	lập trình cơ bản	2026-11-14 08:44:02	2026-11-14 08:44:02	1
\.


--
-- Data for Name: activities; Type: TABLE DATA; Schema: public; Owner: lti
--

COPY public.activities (id, course_id, lti_registration_id, resource_link_id, title, target_uri, maximum, published) FROM stdin;
5	8	3	777bdcf8-06ce-43a3-b5d0-5a5b03a6de83	html cơ bản	https://tmp-reel-merge-legs.trycloudflare.com/lti/launch?content_id=b9df3486-599e-4bcb-81f9-ea81db26aa58	100	t
\.


--
-- Data for Name: activity_assignments; Type: TABLE DATA; Schema: public; Owner: lti
--

COPY public.activity_assignments (activity_id, user_id) FROM stdin;
5	8
\.


--
-- Data for Name: enrollments; Type: TABLE DATA; Schema: public; Owner: lti
--

COPY public.enrollments (course_id, user_id) FROM stdin;
8	8
\.


--
-- Data for Name: grades; Type: TABLE DATA; Schema: public; Owner: lti
--

COPY public.grades (activity_id, user_id, score, reported_at) FROM stdin;
5	8	60.0000	2026-11-17 05:10:45+00
\.


--
-- Name: activities_id_seq; Type: SEQUENCE SET; Schema: public; Owner: lti
--

SELECT pg_catalog.setval('public.activities_id_seq', 5, true);


--
-- Name: courses_id_seq; Type: SEQUENCE SET; Schema: public; Owner: lti
--

SELECT pg_catalog.setval('public.courses_id_seq', 8, true);


--
-- PostgreSQL database dump complete
--

\unrestrict w1fuuqLV9J1HS15IhO34ScAm8cRMiYfTdAOLwgKGotT0b795Fv0XZk68wjZo5R3

