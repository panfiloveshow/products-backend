--
-- PostgreSQL database dump
--


-- Dumped from database version 16.15 (Ubuntu 16.15-0ubuntu0.24.04.1)
-- Dumped by pg_dump version 16.15 (Ubuntu 16.15-0ubuntu0.24.04.1)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: audit_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.audit_logs (
    id uuid NOT NULL,
    auditable_type character varying(255) NOT NULL,
    auditable_id character varying(255) NOT NULL,
    event character varying(255) NOT NULL,
    old_values json,
    new_values json,
    user_id uuid,
    user_name character varying(255),
    ip_address character varying(45),
    user_agent text,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: auto_supply_constraint_files; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.auto_supply_constraint_files (
    id bigint NOT NULL,
    integration_id bigint NOT NULL,
    marketplace character varying(50) NOT NULL,
    file_name character varying(255) NOT NULL,
    file_size_bytes bigint,
    file_hash character varying(128),
    parser_version character varying(80),
    rows_total integer DEFAULT 0 NOT NULL,
    constraints_count integer DEFAULT 0 NOT NULL,
    warnings_count integer DEFAULT 0 NOT NULL,
    cluster_constraints_json json,
    warehouse_constraints_json json,
    summary_json json,
    warnings_json json,
    parsed_at timestamp(0) without time zone,
    last_used_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    source_kind character varying(20) DEFAULT 'file'::character varying NOT NULL
);


--
-- Name: auto_supply_constraint_files_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.auto_supply_constraint_files_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: auto_supply_constraint_files_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.auto_supply_constraint_files_id_seq OWNED BY public.auto_supply_constraint_files.id;


--
-- Name: auto_supply_plan_adjustments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.auto_supply_plan_adjustments (
    id bigint NOT NULL,
    auto_supply_plan_id uuid NOT NULL,
    auto_supply_plan_line_id bigint,
    user_id bigint,
    action character varying(40) NOT NULL,
    old_values_json json,
    new_values_json json,
    reason text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: auto_supply_plan_adjustments_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.auto_supply_plan_adjustments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: auto_supply_plan_adjustments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.auto_supply_plan_adjustments_id_seq OWNED BY public.auto_supply_plan_adjustments.id;


--
-- Name: auto_supply_plan_executions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.auto_supply_plan_executions (
    id uuid NOT NULL,
    auto_supply_plan_id uuid NOT NULL,
    integration_id bigint NOT NULL,
    idempotency_key character varying(120) NOT NULL,
    status character varying(30) DEFAULT 'pending'::character varying NOT NULL,
    plan_fingerprint character varying(64) NOT NULL,
    request_json json,
    result_json json,
    error_json json,
    attempts smallint DEFAULT '0'::smallint NOT NULL,
    initiated_by bigint,
    confirmed_at timestamp(0) without time zone,
    started_at timestamp(0) without time zone,
    finished_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: auto_supply_plan_lines; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.auto_supply_plan_lines (
    id bigint NOT NULL,
    auto_supply_plan_id uuid NOT NULL,
    sku character varying(255) NOT NULL,
    offer_id character varying(255),
    product_name character varying(255),
    barcode character varying(255),
    warehouse_id character varying(255),
    warehouse_name character varying(255),
    destination character varying(255),
    qty_recommended numeric(10,2) DEFAULT '0'::numeric NOT NULL,
    qty_rounded integer DEFAULT 0 NOT NULL,
    current_stock integer DEFAULT 0 NOT NULL,
    in_transit integer DEFAULT 0 NOT NULL,
    avg_daily_sales numeric(10,4) DEFAULT '0'::numeric NOT NULL,
    ewma_daily_sales numeric(10,4) DEFAULT '0'::numeric NOT NULL,
    explain_json json,
    risk_level character varying(10) DEFAULT 'low'::character varying NOT NULL,
    simulation_json json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    tenant_id bigint,
    destination_id character varying(255),
    destination_type character varying(20) DEFAULT 'all'::character varying NOT NULL,
    demand_daily numeric(10,4) DEFAULT '0'::numeric NOT NULL,
    cover_days_before numeric(8,2),
    cover_days_after numeric(8,2),
    oos_date date,
    surplus_days smallint,
    price numeric(10,2),
    cost_price numeric(10,2),
    sales_trend character varying(20),
    sales_trend_percent numeric(8,2),
    storage_cost_daily numeric(10,2),
    storage_cost_monthly numeric(10,2),
    lost_revenue_daily numeric(10,2),
    supply_cost_estimate numeric(10,2),
    expected_revenue numeric(10,2),
    expected_profit numeric(10,2),
    roi_percent numeric(8,2),
    priority_score numeric(8,2),
    priority character varying(20),
    turnover_days numeric(8,1),
    cluster_id integer,
    cluster_name character varying(100),
    region character varying(100),
    own_stock integer,
    own_stock_reserved integer,
    deficit integer,
    sales_7_days integer DEFAULT 0 NOT NULL,
    sales_14_days integer DEFAULT 0 NOT NULL,
    sales_30_days integer DEFAULT 0 NOT NULL,
    local_share_percent numeric(5,2),
    potential_overpayment_rub numeric(14,2),
    lost_margin_rub numeric(14,2),
    expected_local_share_after_pp numeric(5,2),
    expected_savings_rub numeric(14,2),
    locality_confidence character varying(16),
    cluster_split_json json,
    linked_locality_recommendation_ids json,
    parent_line_key character varying(64),
    is_cluster_split boolean DEFAULT false NOT NULL,
    aggregated_qty_rounded integer,
    original_qty_rounded integer,
    is_excluded boolean DEFAULT false NOT NULL,
    manual_comment text,
    manual_override_json json,
    manual_updated_by bigint,
    manual_updated_at timestamp(0) without time zone,
    source_hash character varying(64)
);


--
-- Name: COLUMN auto_supply_plan_lines.cluster_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.auto_supply_plan_lines.cluster_id IS 'ID кластера доставки Ozon';


--
-- Name: COLUMN auto_supply_plan_lines.cluster_name; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.auto_supply_plan_lines.cluster_name IS 'Название кластера';


--
-- Name: COLUMN auto_supply_plan_lines.region; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.auto_supply_plan_lines.region IS 'Регион/город склада';


--
-- Name: COLUMN auto_supply_plan_lines.own_stock; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.auto_supply_plan_lines.own_stock IS 'Остаток на собственном складе продавца';


--
-- Name: COLUMN auto_supply_plan_lines.own_stock_reserved; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.auto_supply_plan_lines.own_stock_reserved IS 'Зарезервировано на собственном складе';


--
-- Name: COLUMN auto_supply_plan_lines.deficit; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.auto_supply_plan_lines.deficit IS 'Дефицит: сколько нужно докупить (qty - available_own_stock)';


--
-- Name: COLUMN auto_supply_plan_lines.local_share_percent; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.auto_supply_plan_lines.local_share_percent IS '% локальных продаж SKU на момент плана (из LocalityMetricDaily)';


--
-- Name: COLUMN auto_supply_plan_lines.potential_overpayment_rub; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.auto_supply_plan_lines.potential_overpayment_rub IS 'Потенциальная переплата за 28 дней по таблице Ozon';


--
-- Name: COLUMN auto_supply_plan_lines.lost_margin_rub; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.auto_supply_plan_lines.lost_margin_rub IS 'Потеря маржи (переплата + дельта базового тарифа)';


--
-- Name: COLUMN auto_supply_plan_lines.expected_local_share_after_pp; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.auto_supply_plan_lines.expected_local_share_after_pp IS 'Прогноз прироста локальности pp от применения плана';


--
-- Name: COLUMN auto_supply_plan_lines.expected_savings_rub; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.auto_supply_plan_lines.expected_savings_rub IS 'Ожидаемая экономия 28 дн. от покрытия LocalityRecommendation';


--
-- Name: COLUMN auto_supply_plan_lines.locality_confidence; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.auto_supply_plan_lines.locality_confidence IS 'low/medium/high — уверенность расчёта локальности';


--
-- Name: COLUMN auto_supply_plan_lines.cluster_split_json; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.auto_supply_plan_lines.cluster_split_json IS 'Массив {cluster_id, cluster_name, qty, expected_savings_rub, rec_id}';


--
-- Name: COLUMN auto_supply_plan_lines.linked_locality_recommendation_ids; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.auto_supply_plan_lines.linked_locality_recommendation_ids IS 'ID рекомендаций Locality, покрытых этой строкой';


--
-- Name: COLUMN auto_supply_plan_lines.parent_line_key; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.auto_supply_plan_lines.parent_line_key IS 'sku:warehouse_id — объединяет split-строки одного SKU';


--
-- Name: COLUMN auto_supply_plan_lines.is_cluster_split; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.auto_supply_plan_lines.is_cluster_split IS 'true если строка — одна из split-child';


--
-- Name: COLUMN auto_supply_plan_lines.aggregated_qty_rounded; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.auto_supply_plan_lines.aggregated_qty_rounded IS 'Σ qty_rounded всех child-строк SKU — для UI сворачивания';


--
-- Name: auto_supply_plan_lines_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.auto_supply_plan_lines_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: auto_supply_plan_lines_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.auto_supply_plan_lines_id_seq OWNED BY public.auto_supply_plan_lines.id;


--
-- Name: auto_supply_plans; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.auto_supply_plans (
    id uuid NOT NULL,
    integration_id bigint NOT NULL,
    marketplace character varying(20) NOT NULL,
    status character varying(20) DEFAULT 'pending'::character varying NOT NULL,
    params json,
    data_quality_score numeric(5,2),
    total_lines integer DEFAULT 0 NOT NULL,
    total_qty integer DEFAULT 0 NOT NULL,
    error_message text,
    export_errors json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    tenant_id bigint,
    mp_account_id bigint,
    mode character varying(20) DEFAULT 'balanced'::character varying NOT NULL,
    horizon_days smallint DEFAULT '28'::smallint NOT NULL,
    min_cover_days smallint DEFAULT '7'::smallint NOT NULL,
    target_cover_days smallint DEFAULT '21'::smallint NOT NULL,
    max_cover_days smallint DEFAULT '42'::smallint NOT NULL,
    safety_stock_days smallint DEFAULT '5'::smallint NOT NULL,
    turnover_limit_days smallint,
    budget_limit numeric(12,2),
    forecast_model character varying(50) DEFAULT 'EWMA_0.35'::character varying NOT NULL,
    algorithm_version character varying(20) DEFAULT 'asp-1.0.0'::character varying NOT NULL,
    data_quality_json json,
    result_json jsonb,
    snapshot_id uuid,
    accuracy_json json,
    business_status character varying(30) DEFAULT 'draft'::character varying NOT NULL,
    approved_at timestamp(0) without time zone,
    approved_by bigint,
    approval_fingerprint character varying(64),
    materialized_at timestamp(0) without time zone,
    requested_params_json json,
    effective_params_json json,
    validation_json json,
    validated_at timestamp(0) without time zone,
    validation_fingerprint character varying(64),
    calculation_engine character varying(30) DEFAULT 'legacy'::character varying NOT NULL,
    forecast_version character varying(40),
    allocation_version character varying(40),
    adapter_version character varying(40),
    code_commit character varying(64),
    execution_started_at timestamp(0) without time zone,
    execution_completed_at timestamp(0) without time zone,
    last_execution_error text
);


--
-- Name: cache; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache (
    key character varying(255) NOT NULL,
    value text NOT NULL,
    expiration integer NOT NULL
);


--
-- Name: cache_locks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache_locks (
    key character varying(255) NOT NULL,
    owner character varying(255) NOT NULL,
    expiration integer NOT NULL
);


--
-- Name: failed_jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.failed_jobs (
    id bigint NOT NULL,
    uuid character varying(255) NOT NULL,
    connection text NOT NULL,
    queue text NOT NULL,
    payload text NOT NULL,
    exception text NOT NULL,
    failed_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.failed_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.failed_jobs_id_seq OWNED BY public.failed_jobs.id;


--
-- Name: integrations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.integrations (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    marketplace character varying(255) NOT NULL,
    credentials text NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    auto_sync_enabled boolean DEFAULT true NOT NULL,
    sync_interval_hours integer DEFAULT 6 NOT NULL,
    last_sync_at timestamp(0) without time zone,
    last_sync_status character varying(255),
    last_sync_error text,
    settings json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    is_premium boolean,
    premium_checked_at timestamp(0) without time zone,
    manual_redemption_rate numeric(5,2),
    localization_checked_at timestamp(0) without time zone,
    redemption_checked_at timestamp(0) without time zone,
    localization_index numeric(4,2),
    sellico_integration_id bigint,
    work_space_id bigint,
    last_validation_at timestamp(0) without time zone,
    last_validation_status character varying(50),
    last_validation_error text,
    non_premium_detection_count smallint DEFAULT '0'::smallint NOT NULL,
    ozon_postings_synced_until timestamp(0) without time zone,
    credential_type character varying(20) DEFAULT 'api_key'::character varying NOT NULL,
    credentials_expires_at timestamp(0) without time zone,
    credential_health character varying(20) DEFAULT 'unknown'::character varying NOT NULL,
    credential_scopes json,
    credential_last_checked_at timestamp(0) without time zone
);


--
-- Name: COLUMN integrations.localization_index; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.integrations.localization_index IS 'Индекс локализации WB (ручной ввод из ЛК)';


--
-- Name: COLUMN integrations.last_validation_at; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.integrations.last_validation_at IS 'Время последней проверки валидности интеграции';


--
-- Name: COLUMN integrations.last_validation_status; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.integrations.last_validation_status IS 'Статус проверки: valid, invalid_token, workspace_mismatch, connection_error, no_token';


--
-- Name: COLUMN integrations.last_validation_error; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.integrations.last_validation_error IS 'Текст ошибки проверки (если есть)';


--
-- Name: integrations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.integrations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: integrations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.integrations_id_seq OWNED BY public.integrations.id;


--
-- Name: inventory_alerts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.inventory_alerts (
    id uuid NOT NULL,
    sku character varying(100) NOT NULL,
    warehouse_id character varying(100),
    warehouse_name character varying(200),
    type character varying(255) NOT NULL,
    message text,
    action character varying(255),
    priority integer,
    is_resolved boolean DEFAULT false NOT NULL,
    resolved_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    integration_id bigint,
    CONSTRAINT inventory_alerts_action_check CHECK (((action)::text = ANY ((ARRAY['reorder'::character varying, 'redistribute'::character varying, 'monitor'::character varying])::text[]))),
    CONSTRAINT inventory_alerts_type_check CHECK (((type)::text = ANY ((ARRAY['critical'::character varying, 'warning'::character varying, 'info'::character varying])::text[])))
);


--
-- Name: inventory_history; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.inventory_history (
    id uuid NOT NULL,
    sku character varying(100) NOT NULL,
    warehouse_id character varying(100) NOT NULL,
    date date NOT NULL,
    quantity integer,
    sales integer,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    integration_id bigint
);


--
-- Name: inventory_warehouses; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.inventory_warehouses (
    id uuid NOT NULL,
    sku character varying(100) NOT NULL,
    warehouse_id character varying(100) NOT NULL,
    warehouse_name character varying(200),
    marketplace character varying(50) NOT NULL,
    region character varying(200),
    quantity integer DEFAULT 0 NOT NULL,
    average_daily_sales numeric(10,2),
    days_of_stock integer,
    recommended_quantity integer,
    stock_status character varying(255) DEFAULT 'optimal'::character varying NOT NULL,
    last_updated timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    fulfillment_type character varying(50),
    sales_7_days integer DEFAULT 0 NOT NULL,
    sales_14_days integer DEFAULT 0 NOT NULL,
    sales_30_days integer DEFAULT 0 NOT NULL,
    turnover_days numeric(8,1),
    reserved integer DEFAULT 0 NOT NULL,
    in_transit integer DEFAULT 0 NOT NULL,
    in_way_to_client integer DEFAULT 0 NOT NULL,
    in_way_from_client integer DEFAULT 0 NOT NULL,
    cost_price numeric(10,2),
    storage_cost_per_day numeric(10,2),
    storage_cost_per_month numeric(10,2),
    days_in_stock_30 integer DEFAULT 30 NOT NULL,
    effective_daily_sales numeric(10,2),
    effective_turnover_days numeric(10,1),
    last_stockout_date date,
    last_restock_date date,
    integration_id bigint,
    sales_28_days integer DEFAULT 0 NOT NULL,
    storage_fee_total numeric(12,2),
    storage_fee_last_week numeric(10,2),
    storage_fee_report_from date,
    storage_fee_report_to date,
    warehouse_coefficient numeric(5,3),
    target_days_of_stock integer,
    safety_stock_days integer,
    reorder_point integer,
    lead_time_days integer,
    optimal_order_quantity integer,
    last_recommendation_at timestamp(0) without time zone,
    real_avg_daily_sales numeric(8,2),
    real_sales_period_days integer,
    real_turnover_days numeric(8,1),
    real_days_of_stock integer,
    sales_report_id uuid,
    paid_storage_penalty numeric(12,2),
    paid_storage_fee numeric(12,2),
    paid_storage_from date,
    paid_storage_to date,
    storage_fee_prev_month numeric(12,2),
    storage_fee_prev_month_period character varying(50),
    storage_fee_all_time numeric(12,2),
    CONSTRAINT inventory_warehouses_stock_status_check CHECK (((stock_status)::text = ANY ((ARRAY['critical'::character varying, 'low'::character varying, 'optimal'::character varying, 'excess'::character varying])::text[])))
);


--
-- Name: COLUMN inventory_warehouses.storage_fee_total; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.inventory_warehouses.storage_fee_total IS 'Фактические начисления за хранение из отчётов WB (сумма за 4 недели)';


--
-- Name: COLUMN inventory_warehouses.storage_fee_last_week; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.inventory_warehouses.storage_fee_last_week IS 'Начисления за последнюю неделю';


--
-- Name: COLUMN inventory_warehouses.storage_fee_report_from; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.inventory_warehouses.storage_fee_report_from IS 'Начало периода отчёта';


--
-- Name: COLUMN inventory_warehouses.storage_fee_report_to; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.inventory_warehouses.storage_fee_report_to IS 'Конец периода отчёта';


--
-- Name: COLUMN inventory_warehouses.warehouse_coefficient; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.inventory_warehouses.warehouse_coefficient IS 'Коэффициент склада (КС) для WB — множитель логистики';


--
-- Name: COLUMN inventory_warehouses.paid_storage_penalty; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.inventory_warehouses.paid_storage_penalty IS 'Штраф за платное хранение (>120 дней), руб';


--
-- Name: COLUMN inventory_warehouses.paid_storage_fee; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.inventory_warehouses.paid_storage_fee IS 'Обычная плата за хранение (StorageFee), руб';


--
-- Name: COLUMN inventory_warehouses.paid_storage_from; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.inventory_warehouses.paid_storage_from IS 'Начало периода начислений';


--
-- Name: COLUMN inventory_warehouses.paid_storage_to; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.inventory_warehouses.paid_storage_to IS 'Конец периода начислений';


--
-- Name: job_batches; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.job_batches (
    id character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    total_jobs integer NOT NULL,
    pending_jobs integer NOT NULL,
    failed_jobs integer NOT NULL,
    failed_job_ids text NOT NULL,
    options text,
    cancelled_at integer,
    created_at integer NOT NULL,
    finished_at integer
);


--
-- Name: jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.jobs (
    id bigint NOT NULL,
    queue character varying(255) NOT NULL,
    payload text NOT NULL,
    attempts smallint NOT NULL,
    reserved_at integer,
    available_at integer NOT NULL,
    created_at integer NOT NULL
);


--
-- Name: jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.jobs_id_seq OWNED BY public.jobs.id;


--
-- Name: locality_metrics_cluster_daily; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.locality_metrics_cluster_daily (
    id bigint NOT NULL,
    integration_id bigint NOT NULL,
    destination_cluster_id character varying(50),
    destination_cluster_name character varying(120) NOT NULL,
    snapshot_date date NOT NULL,
    period_days smallint DEFAULT '28'::smallint NOT NULL,
    orders_count integer DEFAULT 0 NOT NULL,
    local_orders_count integer DEFAULT 0 NOT NULL,
    local_share_percent numeric(6,2),
    total_revenue numeric(14,2) DEFAULT '0'::numeric NOT NULL,
    total_overpayment numeric(14,2) DEFAULT '0'::numeric NOT NULL,
    lost_margin_amount numeric(14,2) DEFAULT '0'::numeric NOT NULL,
    distinct_skus_affected integer DEFAULT 0 NOT NULL,
    top_skus_by_loss json,
    shipping_cluster_breakdown json,
    meta json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: locality_metrics_cluster_daily_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.locality_metrics_cluster_daily_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: locality_metrics_cluster_daily_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.locality_metrics_cluster_daily_id_seq OWNED BY public.locality_metrics_cluster_daily.id;


--
-- Name: locality_metrics_daily; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.locality_metrics_daily (
    id bigint NOT NULL,
    integration_id bigint NOT NULL,
    sku character varying(100) NOT NULL,
    snapshot_date date NOT NULL,
    period_days smallint DEFAULT '28'::smallint NOT NULL,
    orders_count integer DEFAULT 0 NOT NULL,
    local_orders_count integer DEFAULT 0 NOT NULL,
    non_local_orders_count integer DEFAULT 0 NOT NULL,
    local_share_percent numeric(6,2),
    revenue_total numeric(14,2) DEFAULT '0'::numeric NOT NULL,
    base_logistics_total numeric(14,2) DEFAULT '0'::numeric NOT NULL,
    non_local_markup_total numeric(14,2) DEFAULT '0'::numeric NOT NULL,
    overpayment_amount numeric(14,2) DEFAULT '0'::numeric NOT NULL,
    lost_margin_amount numeric(14,2) DEFAULT '0'::numeric NOT NULL,
    avg_base_tariff numeric(12,2),
    avg_markup_percent numeric(6,2),
    factual_orders_count integer DEFAULT 0 NOT NULL,
    estimate_orders_count integer DEFAULT 0 NOT NULL,
    calculation_confidence character varying(16) DEFAULT 'low'::character varying NOT NULL,
    tariff_version_used character varying(32),
    meta json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: locality_metrics_daily_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.locality_metrics_daily_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: locality_metrics_daily_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.locality_metrics_daily_id_seq OWNED BY public.locality_metrics_daily.id;


--
-- Name: locality_recommendations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.locality_recommendations (
    id bigint NOT NULL,
    integration_id bigint NOT NULL,
    sku character varying(100) NOT NULL,
    offer_id character varying(100),
    product_id bigint,
    target_cluster_id character varying(50),
    target_cluster_name character varying(120) NOT NULL,
    recommended_qty_units integer DEFAULT 0 NOT NULL,
    pack_multiple smallint DEFAULT '1'::smallint NOT NULL,
    current_stock_cluster integer DEFAULT 0 NOT NULL,
    in_transit_cluster integer DEFAULT 0 NOT NULL,
    daily_demand_cluster numeric(10,3) DEFAULT '0'::numeric NOT NULL,
    volatility_cluster numeric(6,4),
    gap_units integer DEFAULT 0 NOT NULL,
    expected_savings_rub numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    expected_monthly_savings_rub numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    expected_local_share_uplift_pp numeric(6,2) DEFAULT '0'::numeric NOT NULL,
    expected_days_of_cover numeric(8,2),
    avg_markup_amount_rub numeric(10,2) DEFAULT '0'::numeric NOT NULL,
    avg_base_logistics_rub numeric(10,2) DEFAULT '0'::numeric NOT NULL,
    confidence character varying(16) DEFAULT 'low'::character varying NOT NULL,
    confidence_score numeric(5,2) DEFAULT '0'::numeric NOT NULL,
    rank_score numeric(14,2) DEFAULT '0'::numeric NOT NULL,
    rank_position integer,
    reasoning_text text,
    warnings json,
    constraints_checked json,
    state character varying(32) DEFAULT 'new'::character varying NOT NULL,
    dismissed_at timestamp(0) without time zone,
    dismissed_by bigint,
    dismiss_reason character varying(100),
    applied_at timestamp(0) without time zone,
    applied_by bigint,
    linked_supply_order_id bigint,
    linked_draft_id character varying(50),
    cohort_id uuid NOT NULL,
    computed_at timestamp(0) without time zone NOT NULL,
    period_from date NOT NULL,
    period_to date NOT NULL,
    basis_snapshot_date date NOT NULL,
    lead_time_days smallint DEFAULT '14'::smallint NOT NULL,
    expires_at date,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);


--
-- Name: locality_recommendations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.locality_recommendations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: locality_recommendations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.locality_recommendations_id_seq OWNED BY public.locality_recommendations.id;


--
-- Name: locality_reconciliation_log; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.locality_reconciliation_log (
    id bigint NOT NULL,
    integration_id bigint NOT NULL,
    period_from date NOT NULL,
    period_to date NOT NULL,
    run_at timestamp(0) without time zone NOT NULL,
    source character varying(50) DEFAULT 'finance_transaction_list'::character varying NOT NULL,
    expected_base_logistics numeric(14,2) DEFAULT '0'::numeric NOT NULL,
    expected_non_local_markup numeric(14,2) DEFAULT '0'::numeric NOT NULL,
    actual_base_logistics numeric(14,2) DEFAULT '0'::numeric NOT NULL,
    actual_non_local_markup numeric(14,2) DEFAULT '0'::numeric NOT NULL,
    base_logistics_diff numeric(14,2) DEFAULT '0'::numeric NOT NULL,
    markup_diff numeric(14,2) DEFAULT '0'::numeric NOT NULL,
    base_logistics_diff_percent numeric(6,2),
    markup_diff_percent numeric(6,2),
    verdict character varying(16) DEFAULT 'match'::character varying NOT NULL,
    operations_count integer DEFAULT 0 NOT NULL,
    postings_matched integer DEFAULT 0 NOT NULL,
    postings_missing integer DEFAULT 0 NOT NULL,
    details json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: locality_reconciliation_log_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.locality_reconciliation_log_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: locality_reconciliation_log_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.locality_reconciliation_log_id_seq OWNED BY public.locality_reconciliation_log.id;


--
-- Name: marketplace_constraint_snapshots; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.marketplace_constraint_snapshots (
    id bigint NOT NULL,
    integration_id bigint NOT NULL,
    marketplace character varying(50) NOT NULL,
    cluster_constraints_json json,
    warehouse_constraints_json json,
    summary_json json,
    sources_json json,
    sync_status character varying(20) DEFAULT 'ok'::character varying NOT NULL,
    sync_error text,
    synced_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: marketplace_constraint_snapshots_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.marketplace_constraint_snapshots_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: marketplace_constraint_snapshots_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.marketplace_constraint_snapshots_id_seq OWNED BY public.marketplace_constraint_snapshots.id;


--
-- Name: migrations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.migrations (
    id integer NOT NULL,
    migration character varying(255) NOT NULL,
    batch integer NOT NULL
);


--
-- Name: migrations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.migrations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.migrations_id_seq OWNED BY public.migrations.id;


--
-- Name: ozon_ad_stats; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ozon_ad_stats (
    id bigint NOT NULL,
    integration_id bigint NOT NULL,
    date_from character varying(10) NOT NULL,
    date_to character varying(10) NOT NULL,
    status character varying(20) DEFAULT 'ready'::character varying NOT NULL,
    payload text NOT NULL,
    fetched_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: ozon_ad_stats_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.ozon_ad_stats_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: ozon_ad_stats_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.ozon_ad_stats_id_seq OWNED BY public.ozon_ad_stats.id;


--
-- Name: ozon_finance_transactions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ozon_finance_transactions (
    id bigint NOT NULL,
    integration_id bigint NOT NULL,
    operation_id character varying(64) NOT NULL,
    operation_type character varying(64),
    operation_type_name character varying(128),
    operation_date timestamp(0) without time zone NOT NULL,
    posting_number character varying(64),
    sku character varying(100),
    offer_id character varying(100),
    amount numeric(14,2) DEFAULT '0'::numeric NOT NULL,
    accruals_for_sale numeric(14,2),
    sale_commission numeric(14,2),
    warehouse_id character varying(64),
    warehouse_name character varying(120),
    raw json,
    fetched_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: ozon_finance_transactions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.ozon_finance_transactions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: ozon_finance_transactions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.ozon_finance_transactions_id_seq OWNED BY public.ozon_finance_transactions.id;


--
-- Name: ozon_order_reports; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ozon_order_reports (
    id uuid NOT NULL,
    integration_id bigint NOT NULL,
    filename character varying(255) NOT NULL,
    period_label character varying(255),
    date_from date,
    date_to date,
    total_orders integer DEFAULT 0 NOT NULL,
    total_items integer DEFAULT 0 NOT NULL,
    unique_skus integer DEFAULT 0 NOT NULL,
    unique_warehouses integer DEFAULT 0 NOT NULL,
    status character varying(255) DEFAULT 'processing'::character varying NOT NULL,
    error_message text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: ozon_order_unit_economics; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ozon_order_unit_economics (
    id bigint NOT NULL,
    integration_id bigint NOT NULL,
    posting_id uuid NOT NULL,
    posting_item_id uuid NOT NULL,
    posting_number character varying(255),
    sku character varying(255) NOT NULL,
    offer_id character varying(255),
    order_date timestamp(0) without time zone,
    sale_price numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    volume_liters numeric(12,4),
    price_bucket character varying(255),
    shipping_cluster_id character varying(255),
    shipping_cluster_name character varying(255),
    destination_cluster_id character varying(255),
    destination_cluster_name character varying(255),
    fixation_applied boolean DEFAULT false NOT NULL,
    fixation_id bigint,
    fixation_base_date date,
    fixed_until date,
    tariff_version_used character varying(255),
    markup_version_used character varying(255),
    base_logistics_tariff numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    non_local_markup_percent numeric(8,2) DEFAULT '0'::numeric NOT NULL,
    non_local_markup_amount numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    markup_applied boolean DEFAULT false NOT NULL,
    markup_reason_code character varying(255),
    markup_reason_label character varying(255),
    markup_exception_code character varying(255),
    markup_exception_label character varying(255),
    markup_exception_status character varying(255),
    calculation_mode character varying(255) DEFAULT 'estimate'::character varying NOT NULL,
    calculation_confidence character varying(255) DEFAULT 'low'::character varying NOT NULL,
    meta json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: ozon_order_unit_economics_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.ozon_order_unit_economics_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: ozon_order_unit_economics_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.ozon_order_unit_economics_id_seq OWNED BY public.ozon_order_unit_economics.id;


--
-- Name: ozon_sku_delivery_profiles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ozon_sku_delivery_profiles (
    id bigint NOT NULL,
    integration_id bigint NOT NULL,
    sku character varying(255) NOT NULL,
    offer_id character varying(255),
    ozon_sku character varying(255),
    scheme character varying(20) DEFAULT 'ALL'::character varying NOT NULL,
    stock_profile json,
    sales_profile json,
    cluster_profile json,
    dominant_stock_cluster_id character varying(50),
    dominant_stock_cluster_share numeric(6,2),
    dominant_sales_cluster_id character varying(50),
    dominant_sales_cluster_share numeric(6,2),
    dominant_demand_cluster_id character varying(50),
    dominant_demand_cluster_share numeric(6,2),
    expected_locality_rate numeric(6,2),
    weighted_non_local_markup_percent numeric(6,2),
    weighted_logistics_cost numeric(12,2),
    profile_source character varying(50),
    route_resolution_status character varying(20),
    locality_resolution_status character varying(20),
    calculation_confidence character varying(20),
    calculated_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: ozon_sku_delivery_profiles_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.ozon_sku_delivery_profiles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: ozon_sku_delivery_profiles_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.ozon_sku_delivery_profiles_id_seq OWNED BY public.ozon_sku_delivery_profiles.id;


--
-- Name: ozon_supply_fixations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ozon_supply_fixations (
    id bigint NOT NULL,
    integration_id bigint NOT NULL,
    supply_id bigint NOT NULL,
    sku character varying(255) NOT NULL,
    offer_id character varying(255),
    shipping_cluster_id character varying(255),
    shipping_cluster_name character varying(255),
    fixation_base_date date NOT NULL,
    fixed_until date NOT NULL,
    tariff_version character varying(255) NOT NULL,
    markup_version character varying(255) NOT NULL,
    announcement_effective_from date,
    source character varying(255) DEFAULT 'supply_created'::character varying NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    meta json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: ozon_supply_fixations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.ozon_supply_fixations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: ozon_supply_fixations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.ozon_supply_fixations_id_seq OWNED BY public.ozon_supply_fixations.id;


--
-- Name: ozon_warehouse_clusters; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ozon_warehouse_clusters (
    id bigint NOT NULL,
    warehouse_name character varying(100) NOT NULL,
    warehouse_name_normalized character varying(100) NOT NULL,
    cluster_id integer NOT NULL,
    cluster_name character varying(100) NOT NULL,
    region character varying(100),
    is_negabarit boolean DEFAULT false NOT NULL,
    is_jewelry boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    latitude numeric(10,6),
    longitude numeric(10,6),
    is_hub boolean DEFAULT false NOT NULL,
    ozon_source character varying(32),
    last_refreshed_at timestamp(0) without time zone,
    logistic_clusters json
);


--
-- Name: COLUMN ozon_warehouse_clusters.warehouse_name; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.ozon_warehouse_clusters.warehouse_name IS 'Название склада (как в Ozon API)';


--
-- Name: COLUMN ozon_warehouse_clusters.warehouse_name_normalized; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.ozon_warehouse_clusters.warehouse_name_normalized IS 'Нормализованное название для поиска';


--
-- Name: COLUMN ozon_warehouse_clusters.cluster_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.ozon_warehouse_clusters.cluster_id IS 'ID кластера доставки Ozon';


--
-- Name: COLUMN ozon_warehouse_clusters.cluster_name; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.ozon_warehouse_clusters.cluster_name IS 'Название кластера';


--
-- Name: COLUMN ozon_warehouse_clusters.region; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.ozon_warehouse_clusters.region IS 'Регион/город';


--
-- Name: COLUMN ozon_warehouse_clusters.is_negabarit; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.ozon_warehouse_clusters.is_negabarit IS 'Склад для негабаритных товаров';


--
-- Name: COLUMN ozon_warehouse_clusters.is_jewelry; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.ozon_warehouse_clusters.is_jewelry IS 'Склад для ювелирных товаров';


--
-- Name: ozon_warehouse_clusters_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.ozon_warehouse_clusters_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: ozon_warehouse_clusters_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.ozon_warehouse_clusters_id_seq OWNED BY public.ozon_warehouse_clusters.id;


--
-- Name: ozon_warehouse_sales; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ozon_warehouse_sales (
    id uuid NOT NULL,
    report_id uuid NOT NULL,
    integration_id bigint NOT NULL,
    sku character varying(100) NOT NULL,
    article character varying(100),
    product_name character varying(255),
    warehouse_name character varying(200) NOT NULL,
    shipment_cluster character varying(200),
    delivery_cluster character varying(200),
    orders_count integer DEFAULT 0 NOT NULL,
    items_sold integer DEFAULT 0 NOT NULL,
    revenue numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    avg_daily_sales numeric(8,2) DEFAULT '0'::numeric NOT NULL,
    period_days integer DEFAULT 14 NOT NULL,
    date_from date,
    date_to date,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: password_reset_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.password_reset_tokens (
    email character varying(255) NOT NULL,
    token character varying(255) NOT NULL,
    created_at timestamp(0) without time zone
);


--
-- Name: personal_access_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.personal_access_tokens (
    id bigint NOT NULL,
    tokenable_type character varying(255) NOT NULL,
    tokenable_id bigint NOT NULL,
    name text NOT NULL,
    token character varying(64) NOT NULL,
    abilities text,
    last_used_at timestamp(0) without time zone,
    expires_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.personal_access_tokens_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.personal_access_tokens_id_seq OWNED BY public.personal_access_tokens.id;


--
-- Name: plan_line_evaluations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.plan_line_evaluations (
    id bigint NOT NULL,
    auto_supply_plan_id uuid NOT NULL,
    auto_supply_plan_line_id bigint NOT NULL,
    integration_id bigint NOT NULL,
    marketplace character varying(50) NOT NULL,
    sku character varying(255) NOT NULL,
    cluster_id character varying(255),
    warehouse_id character varying(255),
    plan_created_at timestamp(0) without time zone NOT NULL,
    horizon_days integer NOT NULL,
    evaluated_at timestamp(0) without time zone NOT NULL,
    forecast_demand_qty numeric(12,2),
    actual_sales_qty numeric(12,2),
    abs_pct_error numeric(8,2),
    bias_pct numeric(8,2),
    demand_fact_source character varying(40),
    planned_qty integer,
    accepted_qty integer,
    rejected_qty integer,
    acceptance_rate numeric(6,2),
    status character varying(20) DEFAULT 'ok'::character varying NOT NULL,
    details_json json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: plan_line_evaluations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.plan_line_evaluations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: plan_line_evaluations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.plan_line_evaluations_id_seq OWNED BY public.plan_line_evaluations.id;


--
-- Name: planning_fact_snapshots; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.planning_fact_snapshots (
    id uuid NOT NULL,
    auto_supply_plan_id uuid NOT NULL,
    integration_id bigint NOT NULL,
    marketplace character varying(50) NOT NULL,
    status character varying(32) DEFAULT 'building'::character varying NOT NULL,
    captured_at timestamp(0) without time zone,
    params_json json,
    facts_freshness_json json,
    planning_sources_json json,
    demand_facts_json json,
    stock_facts_json json,
    supply_facts_json json,
    economics_facts_json json,
    constraints_facts_json json,
    summary_json json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: posting_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.posting_items (
    id uuid NOT NULL,
    posting_id uuid NOT NULL,
    sku character varying(255) NOT NULL,
    marketplace_sku character varying(255),
    offer_id character varying(255),
    barcode character varying(255),
    name character varying(255) NOT NULL,
    image_url character varying(255),
    quantity integer DEFAULT 1 NOT NULL,
    price numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    total_price numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    commission_amount numeric(12,2),
    commission_percent numeric(5,2),
    payout numeric(12,2),
    weight numeric(10,3),
    volume numeric(10,6),
    status character varying(50),
    is_assembled boolean DEFAULT false NOT NULL,
    is_packed boolean DEFAULT false NOT NULL,
    meta json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: postings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.postings (
    id uuid NOT NULL,
    integration_id character varying(255) NOT NULL,
    marketplace character varying(50) NOT NULL,
    posting_number character varying(255) NOT NULL,
    order_id character varying(255),
    order_number character varying(255),
    status character varying(50) DEFAULT 'awaiting_packaging'::character varying NOT NULL,
    substatus character varying(50),
    external_status character varying(100),
    shipment_date timestamp(0) without time zone,
    delivering_date timestamp(0) without time zone,
    in_process_at timestamp(0) without time zone,
    packed_at timestamp(0) without time zone,
    shipped_at timestamp(0) without time zone,
    delivered_at timestamp(0) without time zone,
    cancelled_at timestamp(0) without time zone,
    warehouse_id character varying(255),
    warehouse_name character varying(255),
    delivery_method character varying(255),
    delivery_type character varying(255),
    tpl_integration_type character varying(255),
    customer json,
    total_price numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    products_total numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    commission numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    delivery_cost numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    payout numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    financial_data json,
    items_count integer DEFAULT 0 NOT NULL,
    total_quantity integer DEFAULT 0 NOT NULL,
    cancel_reason_id integer,
    cancel_reason_message character varying(255),
    meta json,
    analytics_data json,
    barcodes json,
    synced_at timestamp(0) without time zone,
    last_status_change_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: products; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.products (
    id uuid NOT NULL,
    sku character varying(100) NOT NULL,
    name character varying(500) NOT NULL,
    barcode character varying(50),
    price numeric(12,2),
    old_price numeric(12,2),
    stock integer DEFAULT 0 NOT NULL,
    description text,
    images json,
    category character varying(200),
    brand character varying(200),
    rating numeric(5,2),
    reviews_count integer DEFAULT 0 NOT NULL,
    marketplace character varying(50) NOT NULL,
    marketplace_id character varying(100),
    url text,
    wb_data json,
    ozon_data json,
    yandex_data json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    characteristics json,
    integration_id bigint,
    fulfillment_type character varying(20),
    depth numeric(10,2),
    width numeric(10,2),
    height numeric(10,2),
    weight numeric(10,2),
    volume_weight numeric(10,4),
    sales_28_days integer DEFAULT 0 NOT NULL,
    avg_daily_sales numeric(10,2) DEFAULT '0'::numeric NOT NULL,
    turnover_days numeric(10,1),
    storage_cost numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    storage_cost_updated_at timestamp(0) without time zone,
    card_rating numeric(3,1),
    card_rating_details json,
    commission numeric(5,2),
    spp numeric(5,2),
    subject_id integer,
    cost_price numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    vendor_code character varying(255),
    uzum_data json,
    storage_cost_per_unit numeric(10,2)
);


--
-- Name: COLUMN products.fulfillment_type; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.products.fulfillment_type IS 'Схема работы: FBO, FBS, REALFBS, EXPRESS';


--
-- Name: COLUMN products.depth; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.products.depth IS 'Длина (мм)';


--
-- Name: COLUMN products.width; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.products.width IS 'Ширина (мм)';


--
-- Name: COLUMN products.height; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.products.height IS 'Высота (мм)';


--
-- Name: COLUMN products.weight; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.products.weight IS 'Вес (г)';


--
-- Name: COLUMN products.volume_weight; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.products.volume_weight IS 'Объём (л)';


--
-- Name: COLUMN products.card_rating; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.products.card_rating IS 'Рейтинг карточки (качество заполнения) 0-10';


--
-- Name: COLUMN products.card_rating_details; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.products.card_rating_details IS 'Детали рейтинга: title, description, photos, characteristics';


--
-- Name: COLUMN products.commission; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.products.commission IS 'Комиссия WB (%) по категории товара';


--
-- Name: COLUMN products.spp; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.products.spp IS 'СПП (%) — Скидка Постоянного Покупателя';


--
-- Name: COLUMN products.subject_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.products.subject_id IS 'ID категории WB для получения комиссии';


--
-- Name: seller_warehouse_stocks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.seller_warehouse_stocks (
    id bigint NOT NULL,
    integration_id bigint NOT NULL,
    sku character varying(100) NOT NULL,
    barcode character varying(100),
    product_name character varying(500),
    quantity integer DEFAULT 0 NOT NULL,
    reserved integer DEFAULT 0 NOT NULL,
    cost_price numeric(12,2),
    location character varying(200),
    note text,
    last_counted_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: COLUMN seller_warehouse_stocks.quantity; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.seller_warehouse_stocks.quantity IS 'Остаток на собственном складе';


--
-- Name: COLUMN seller_warehouse_stocks.reserved; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.seller_warehouse_stocks.reserved IS 'Зарезервировано (уже в процессе отправки)';


--
-- Name: COLUMN seller_warehouse_stocks.cost_price; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.seller_warehouse_stocks.cost_price IS 'Себестоимость единицы';


--
-- Name: COLUMN seller_warehouse_stocks.location; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.seller_warehouse_stocks.location IS 'Место хранения / ячейка';


--
-- Name: COLUMN seller_warehouse_stocks.note; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.seller_warehouse_stocks.note IS 'Заметка продавца';


--
-- Name: COLUMN seller_warehouse_stocks.last_counted_at; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.seller_warehouse_stocks.last_counted_at IS 'Дата последней инвентаризации';


--
-- Name: seller_warehouse_stocks_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.seller_warehouse_stocks_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: seller_warehouse_stocks_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.seller_warehouse_stocks_id_seq OWNED BY public.seller_warehouse_stocks.id;


--
-- Name: sessions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sessions (
    id character varying(255) NOT NULL,
    user_id bigint,
    ip_address character varying(45),
    user_agent text,
    payload text NOT NULL,
    last_activity integer NOT NULL
);


--
-- Name: shipment_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.shipment_items (
    id uuid NOT NULL,
    shipment_id uuid NOT NULL,
    sku character varying(100) NOT NULL,
    product_name character varying(500),
    image_url text,
    current_stock integer,
    days_of_stock integer,
    priority character varying(255) DEFAULT 'medium'::character varying NOT NULL,
    quantity integer NOT NULL,
    cost_price numeric(10,2),
    total_cost numeric(12,2),
    ml_recommended boolean DEFAULT false NOT NULL,
    ml_quantity integer,
    ml_reason text,
    ml_confidence numeric(5,2),
    volume_per_unit numeric(10,6),
    weight_per_unit numeric(10,3),
    total_volume numeric(10,3),
    total_weight numeric(10,3),
    marketplaces json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT shipment_items_priority_check CHECK (((priority)::text = ANY ((ARRAY['critical'::character varying, 'medium'::character varying, 'low'::character varying])::text[])))
);


--
-- Name: shipment_recommendations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.shipment_recommendations (
    id uuid NOT NULL,
    priority character varying(255) NOT NULL,
    title character varying(300),
    description text,
    critical_items json,
    recommended_items json,
    total_cost numeric(12,2),
    total_volume numeric(10,3),
    estimated_delivery_cost numeric(10,2),
    reason text,
    deadline date,
    seasonal_factors json,
    is_used boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    integration_id bigint,
    CONSTRAINT shipment_recommendations_priority_check CHECK (((priority)::text = ANY ((ARRAY['urgent'::character varying, 'high'::character varying, 'medium'::character varying])::text[])))
);


--
-- Name: shipments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.shipments (
    id uuid NOT NULL,
    name character varying(200) NOT NULL,
    status character varying(255) DEFAULT 'draft'::character varying NOT NULL,
    marketplace character varying(255) NOT NULL,
    shipment_type character varying(255) NOT NULL,
    warehouse_name character varying(200),
    supplier_id uuid,
    supplier_name character varying(300),
    supplier_address text,
    slot json,
    marketplace_requirements json,
    packaging json,
    total_items integer DEFAULT 0 NOT NULL,
    total_quantity integer DEFAULT 0 NOT NULL,
    total_cost numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    total_volume numeric(10,3) DEFAULT '0'::numeric NOT NULL,
    total_weight numeric(10,3) DEFAULT '0'::numeric NOT NULL,
    truck_type character varying(50),
    truck_capacity numeric(10,2),
    delivery_cost numeric(10,2),
    delivery_cost_percent numeric(5,2),
    utilization_percent numeric(5,2),
    logistics_approval json,
    created_by uuid NOT NULL,
    created_by_name character varying(200),
    sent_at timestamp(0) without time zone,
    delivered_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    integration_id character varying(255),
    warehouse_id character varying(255),
    meta json,
    planned_date date,
    description text,
    supply_plan_id uuid,
    external_supply_id character varying(255),
    external_status character varying(255),
    synced_at timestamp(0) without time zone,
    macrolocal_cluster_id character varying(50),
    cluster_name character varying(200),
    supply_method character varying(255),
    delivery_scheme character varying(255),
    workspace_id bigint,
    CONSTRAINT shipments_delivery_scheme_check CHECK (((delivery_scheme)::text = ANY ((ARRAY['drop_off'::character varying, 'pick_up'::character varying])::text[]))),
    CONSTRAINT shipments_marketplace_check CHECK (((marketplace)::text = ANY ((ARRAY['wildberries'::character varying, 'ozon'::character varying, 'yandex'::character varying])::text[]))),
    CONSTRAINT shipments_shipment_type_check CHECK (((shipment_type)::text = ANY ((ARRAY['fbo'::character varying, 'fbs'::character varying, 'dbs'::character varying])::text[]))),
    CONSTRAINT shipments_status_check CHECK (((status)::text = ANY ((ARRAY['draft'::character varying, 'pending_logistics'::character varying, 'submitted'::character varying, 'pending_confirmation'::character varying, 'confirmed'::character varying, 'approved'::character varying, 'sent'::character varying, 'in_transit'::character varying, 'arrived'::character varying, 'processing'::character varying, 'partially_accepted'::character varying, 'delivered'::character varying, 'rejected'::character varying, 'cancelled'::character varying])::text[]))),
    CONSTRAINT shipments_supply_method_check CHECK (((supply_method)::text = ANY ((ARRAY['direct'::character varying, 'crossdock'::character varying, 'multi_cluster'::character varying])::text[])))
);


--
-- Name: COLUMN shipments.macrolocal_cluster_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.shipments.macrolocal_cluster_id IS 'ID макролокального кластера Ozon (для кросс-дока)';


--
-- Name: COLUMN shipments.cluster_name; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.shipments.cluster_name IS 'Название кластера Ozon';


--
-- Name: COLUMN shipments.supply_method; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.shipments.supply_method IS 'Метод поставки: прямая, кросс-док, мультикластерная';


--
-- Name: COLUMN shipments.delivery_scheme; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.shipments.delivery_scheme IS 'Схема доставки для кросс-дока: Drop Off или Pick Up';


--
-- Name: suppliers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.suppliers (
    id uuid NOT NULL,
    name character varying(300) NOT NULL,
    address text,
    phone character varying(50),
    email character varying(200),
    contact_person character varying(200),
    metadata json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    workspace_id bigint
);


--
-- Name: supplies; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.supplies (
    id bigint NOT NULL,
    integration_id bigint NOT NULL,
    crm_number character varying(50) NOT NULL,
    ozon_supply_id character varying(50),
    ozon_draft_id character varying(50),
    supply_type character varying(255) DEFAULT 'fbo'::character varying NOT NULL,
    supply_method character varying(255) DEFAULT 'direct'::character varying NOT NULL,
    delivery_scheme character varying(255),
    cluster_id character varying(50),
    cluster_name character varying(200),
    warehouse_id character varying(50),
    warehouse_name character varying(200),
    drop_off_point_id character varying(50),
    drop_off_point_type character varying(50),
    seller_warehouse_id character varying(50),
    timeslot_id character varying(50),
    timeslot_from timestamp(0) without time zone,
    timeslot_to timestamp(0) without time zone,
    planned_delivery_date date,
    items_count integer DEFAULT 0 NOT NULL,
    total_quantity integer DEFAULT 0 NOT NULL,
    total_boxes integer DEFAULT 0 NOT NULL,
    total_weight numeric(12,3) DEFAULT '0'::numeric NOT NULL,
    total_volume numeric(12,6) DEFAULT '0'::numeric NOT NULL,
    status character varying(30) DEFAULT 'draft'::character varying NOT NULL,
    ozon_status character varying(100),
    ozon_status_description character varying(500),
    created_in_ozon_at timestamp(0) without time zone,
    slot_booked_at timestamp(0) without time zone,
    preparing_started_at timestamp(0) without time zone,
    ready_to_ship_at timestamp(0) without time zone,
    shipped_at timestamp(0) without time zone,
    arrived_at timestamp(0) without time zone,
    accepted_at timestamp(0) without time zone,
    closed_at timestamp(0) without time zone,
    accepted_quantity integer,
    rejected_quantity integer,
    acceptance_discrepancies json,
    created_by bigint,
    responsible_id bigint,
    supply_plan_id uuid,
    comment text,
    meta json,
    ozon_response json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    auto_supply_plan_id uuid,
    ozon_order_id character varying(255),
    auto_supply_plan_execution_id uuid,
    execution_step character varying(30),
    execution_error text,
    external_last_synced_at timestamp(0) without time zone,
    CONSTRAINT supplies_delivery_scheme_check CHECK (((delivery_scheme)::text = ANY ((ARRAY['drop_off'::character varying, 'pick_up'::character varying])::text[]))),
    CONSTRAINT supplies_supply_method_check CHECK (((supply_method)::text = ANY ((ARRAY['direct'::character varying, 'crossdock'::character varying, 'multi_cluster'::character varying])::text[]))),
    CONSTRAINT supplies_supply_type_check CHECK (((supply_type)::text = ANY ((ARRAY['fbo'::character varying, 'fbs'::character varying, 'realfbs'::character varying])::text[])))
);


--
-- Name: supplies_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.supplies_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: supplies_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.supplies_id_seq OWNED BY public.supplies.id;


--
-- Name: supply_analytics; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.supply_analytics (
    id bigint NOT NULL,
    integration_id bigint NOT NULL,
    date date NOT NULL,
    period_type character varying(10) DEFAULT 'daily'::character varying NOT NULL,
    cluster_id character varying(50),
    warehouse_id character varying(50),
    sku character varying(100),
    recommendations_generated integer DEFAULT 0 NOT NULL,
    recommendations_accepted integer DEFAULT 0 NOT NULL,
    recommendations_rejected integer DEFAULT 0 NOT NULL,
    recommendations_expired integer DEFAULT 0 NOT NULL,
    fill_rate numeric(5,2),
    oos_skus_count integer DEFAULT 0 NOT NULL,
    oos_rate numeric(5,2),
    oos_revenue_lost numeric(15,2),
    overstock_skus_count integer DEFAULT 0 NOT NULL,
    overstock_rate numeric(5,2),
    overstock_value numeric(15,2),
    forecast_accuracy numeric(5,2),
    demand_vs_actual numeric(8,2),
    supplies_created integer DEFAULT 0 NOT NULL,
    supplies_completed integer DEFAULT 0 NOT NULL,
    supplies_cancelled integer DEFAULT 0 NOT NULL,
    supplies_with_errors integer DEFAULT 0 NOT NULL,
    slots_booked integer DEFAULT 0 NOT NULL,
    slots_changed integer DEFAULT 0 NOT NULL,
    slots_missed integer DEFAULT 0 NOT NULL,
    avg_lead_time_days numeric(5,2),
    planned_vs_actual_lead_time numeric(5,2),
    items_shipped integer DEFAULT 0 NOT NULL,
    items_accepted integer DEFAULT 0 NOT NULL,
    items_rejected integer DEFAULT 0 NOT NULL,
    acceptance_rate numeric(5,2),
    discrepancies_count integer DEFAULT 0 NOT NULL,
    sla_on_time_rate numeric(5,2),
    sla_violations integer DEFAULT 0 NOT NULL,
    total_supplied_value numeric(15,2),
    logistics_cost numeric(15,2),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: supply_analytics_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.supply_analytics_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: supply_analytics_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.supply_analytics_id_seq OWNED BY public.supply_analytics.id;


--
-- Name: supply_documents; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.supply_documents (
    id bigint NOT NULL,
    supply_id bigint NOT NULL,
    package_id bigint,
    document_type character varying(30) NOT NULL,
    document_name character varying(255) NOT NULL,
    description text,
    format character varying(10) DEFAULT 'pdf'::character varying NOT NULL,
    source character varying(20) DEFAULT 'system'::character varying NOT NULL,
    file_path character varying(500),
    file_url character varying(1000),
    file_size bigint,
    file_hash character varying(64),
    barcode_data text,
    barcode_type character varying(20),
    ozon_document_id character varying(100),
    status character varying(20) DEFAULT 'pending'::character varying NOT NULL,
    error_message text,
    generated_at timestamp(0) without time zone,
    generated_by bigint,
    downloaded_count integer DEFAULT 0 NOT NULL,
    last_downloaded_at timestamp(0) without time zone,
    expires_at timestamp(0) without time zone,
    meta json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: supply_documents_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.supply_documents_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: supply_documents_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.supply_documents_id_seq OWNED BY public.supply_documents.id;


--
-- Name: supply_events; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.supply_events (
    id bigint NOT NULL,
    supply_id bigint NOT NULL,
    event_type character varying(30) NOT NULL,
    title character varying(200),
    description text,
    old_value character varying(500),
    new_value character varying(500),
    changes json,
    api_method character varying(100),
    api_endpoint character varying(200),
    api_request_body json,
    api_response_body json,
    api_response_code integer,
    api_duration_ms integer,
    error_code character varying(50),
    error_message text,
    error_context json,
    is_critical boolean DEFAULT false NOT NULL,
    is_resolved boolean DEFAULT false NOT NULL,
    resolved_at timestamp(0) without time zone,
    initiated_by character varying(20) DEFAULT 'system'::character varying NOT NULL,
    user_id bigint,
    ip_address character varying(45),
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: supply_events_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.supply_events_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: supply_events_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.supply_events_id_seq OWNED BY public.supply_events.id;


--
-- Name: supply_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.supply_items (
    id bigint NOT NULL,
    supply_id bigint NOT NULL,
    product_id uuid,
    sku character varying(100) NOT NULL,
    ozon_product_id character varying(50),
    barcode character varying(100),
    product_name character varying(500),
    planned_qty integer DEFAULT 0 NOT NULL,
    packed_qty integer DEFAULT 0 NOT NULL,
    shipped_qty integer DEFAULT 0 NOT NULL,
    accepted_qty integer,
    rejected_qty integer,
    pack_multiple integer DEFAULT 1 NOT NULL,
    boxes_count integer DEFAULT 0 NOT NULL,
    weight numeric(10,3),
    length numeric(10,2),
    width numeric(10,2),
    height numeric(10,2),
    status character varying(20) DEFAULT 'pending'::character varying NOT NULL,
    rejection_reason character varying(500),
    recommendation_id bigint,
    meta json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    auto_supply_plan_line_id bigint
);


--
-- Name: supply_items_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.supply_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: supply_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.supply_items_id_seq OWNED BY public.supply_items.id;


--
-- Name: supply_package_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.supply_package_items (
    id bigint NOT NULL,
    package_id bigint NOT NULL,
    supply_item_id bigint,
    product_id uuid,
    sku character varying(100) NOT NULL,
    barcode character varying(100),
    product_name character varying(500),
    quantity integer DEFAULT 1 NOT NULL,
    weight numeric(10,3),
    scanned_at timestamp(0) without time zone,
    scanned_by bigint,
    meta json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    expiry_date date
);


--
-- Name: supply_package_items_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.supply_package_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: supply_package_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.supply_package_items_id_seq OWNED BY public.supply_package_items.id;


--
-- Name: supply_packages; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.supply_packages (
    id bigint NOT NULL,
    supply_id bigint NOT NULL,
    package_type character varying(20) DEFAULT 'box'::character varying NOT NULL,
    sequence_number integer DEFAULT 1 NOT NULL,
    barcode character varying(50) NOT NULL,
    ozon_package_id character varying(50),
    weight numeric(10,3),
    length numeric(8,2),
    width numeric(8,2),
    height numeric(8,2),
    items_count integer DEFAULT 0 NOT NULL,
    total_quantity integer DEFAULT 0 NOT NULL,
    status character varying(20) DEFAULT 'draft'::character varying NOT NULL,
    packed_at timestamp(0) without time zone,
    packed_by bigint,
    label_printed_at timestamp(0) without time zone,
    label_print_count integer DEFAULT 0 NOT NULL,
    shipped_at timestamp(0) without time zone,
    accepted_at timestamp(0) without time zone,
    accepted_quantity integer,
    rejected_quantity integer,
    rejection_reason text,
    meta json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: supply_packages_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.supply_packages_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: supply_packages_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.supply_packages_id_seq OWNED BY public.supply_packages.id;


--
-- Name: supply_plans; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.supply_plans (
    id uuid NOT NULL,
    name character varying(255) NOT NULL,
    description text,
    period_start date NOT NULL,
    period_end date NOT NULL,
    integration_id bigint,
    marketplace character varying(20) NOT NULL,
    target_days_of_stock integer DEFAULT 30 NOT NULL,
    safety_stock_days integer DEFAULT 7 NOT NULL,
    total_items integer DEFAULT 0 NOT NULL,
    total_quantity integer DEFAULT 0 NOT NULL,
    total_cost numeric(14,2) DEFAULT '0'::numeric NOT NULL,
    total_volume numeric(10,3) DEFAULT '0'::numeric NOT NULL,
    total_weight numeric(10,3) DEFAULT '0'::numeric NOT NULL,
    estimated_storage_cost numeric(12,2),
    estimated_profit numeric(14,2),
    estimated_roi numeric(6,2),
    status character varying(30) DEFAULT 'draft'::character varying NOT NULL,
    created_by uuid,
    created_by_name character varying(255),
    approved_by uuid,
    approved_by_name character varying(255),
    approved_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    macrolocal_cluster_id character varying(50),
    cluster_name character varying(200),
    warehouse_id character varying(50),
    warehouse_name character varying(200),
    supply_method character varying(255),
    delivery_scheme character varying(255),
    CONSTRAINT supply_plans_delivery_scheme_check CHECK (((delivery_scheme)::text = ANY ((ARRAY['drop_off'::character varying, 'pick_up'::character varying])::text[]))),
    CONSTRAINT supply_plans_supply_method_check CHECK (((supply_method)::text = ANY ((ARRAY['direct'::character varying, 'crossdock'::character varying, 'multi_cluster'::character varying])::text[])))
);


--
-- Name: COLUMN supply_plans.macrolocal_cluster_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.supply_plans.macrolocal_cluster_id IS 'ID макролокального кластера Ozon';


--
-- Name: COLUMN supply_plans.cluster_name; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.supply_plans.cluster_name IS 'Название кластера Ozon';


--
-- Name: COLUMN supply_plans.warehouse_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.supply_plans.warehouse_id IS 'ID склада (для прямых поставок)';


--
-- Name: COLUMN supply_plans.warehouse_name; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.supply_plans.warehouse_name IS 'Название склада';


--
-- Name: COLUMN supply_plans.supply_method; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.supply_plans.supply_method IS 'Метод поставки';


--
-- Name: COLUMN supply_plans.delivery_scheme; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.supply_plans.delivery_scheme IS 'Схема доставки для кросс-дока';


--
-- Name: supply_recommendations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.supply_recommendations (
    integration_id bigint,
    marketplace character varying(20) NOT NULL,
    warehouse_id character varying(100),
    warehouse_name character varying(255),
    priority character varying(20) DEFAULT 'medium'::character varying NOT NULL,
    title character varying(255) NOT NULL,
    description text,
    reason text,
    critical_items json,
    recommended_items json,
    total_items integer DEFAULT 0 NOT NULL,
    total_quantity integer DEFAULT 0 NOT NULL,
    total_cost numeric(14,2) DEFAULT '0'::numeric NOT NULL,
    total_volume numeric(10,3) DEFAULT '0'::numeric NOT NULL,
    total_weight numeric(10,3) DEFAULT '0'::numeric NOT NULL,
    estimated_delivery_cost numeric(10,2),
    estimated_storage_cost numeric(10,2),
    estimated_profit numeric(14,2),
    deadline date,
    seasonal_factors json,
    is_used boolean DEFAULT false NOT NULL,
    used_in_shipment_id uuid,
    used_at timestamp(0) without time zone,
    is_dismissed boolean DEFAULT false NOT NULL,
    dismissed_at timestamp(0) without time zone,
    dismissed_reason character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    product_id uuid,
    ozon_product_id character varying(50),
    cluster_id character varying(50),
    cluster_name character varying(200),
    avg_sales_7d numeric(12,4) DEFAULT '0'::numeric NOT NULL,
    avg_sales_14d numeric(12,4) DEFAULT '0'::numeric NOT NULL,
    avg_sales_28d numeric(12,4) DEFAULT '0'::numeric NOT NULL,
    state character varying(20) DEFAULT 'new'::character varying NOT NULL,
    oos_risk boolean DEFAULT false NOT NULL,
    overstock_risk boolean DEFAULT false NOT NULL,
    supply_id bigint,
    deleted_at timestamp(0) without time zone,
    sku character varying(100),
    product_name character varying(500),
    avg_sales_used numeric(12,4) DEFAULT '0'::numeric NOT NULL,
    sales_window character varying(10) DEFAULT '14d'::character varying NOT NULL,
    current_stock integer DEFAULT 0 NOT NULL,
    in_transit integer DEFAULT 0 NOT NULL,
    safety_stock integer DEFAULT 0 NOT NULL,
    target_days integer DEFAULT 14 NOT NULL,
    demand integer DEFAULT 0 NOT NULL,
    need_raw integer DEFAULT 0 NOT NULL,
    recommended_qty integer DEFAULT 0 NOT NULL,
    pack_multiple integer DEFAULT 1 NOT NULL,
    min_order_qty integer DEFAULT 1 NOT NULL,
    priority_score numeric(8,2) DEFAULT '0'::numeric NOT NULL,
    days_of_stock integer DEFAULT 0 NOT NULL,
    reasons json,
    warnings json,
    restrictions json,
    recommended_create_date date,
    recommended_delivery_date date,
    lead_time_days integer DEFAULT 3 NOT NULL,
    user_qty integer,
    user_comment text,
    processed_by bigint,
    processed_at timestamp(0) without time zone,
    supply_plan_id bigint,
    id bigint NOT NULL,
    sales_trend character varying(20),
    sales_trend_percent numeric(8,2),
    oos_date date,
    days_until_oos integer,
    lost_revenue_daily numeric(12,2),
    lost_revenue_potential numeric(12,2),
    sales_volatility numeric(8,4),
    safety_stock_dynamic integer,
    supply_cost_estimate numeric(12,2),
    expected_revenue numeric(12,2),
    expected_profit numeric(12,2),
    roi_percent numeric(8,2),
    price numeric(12,2),
    cost_price numeric(12,2),
    margin_percent numeric(8,2),
    explanations json,
    redemption_rate numeric(5,2),
    turnover_days_ue numeric(8,2),
    localization_index numeric(5,2),
    storage_cost numeric(12,2),
    delivery_cost numeric(12,2),
    drr_percent numeric(5,2),
    ozon_recommended_supply integer,
    ozon_lost_profit numeric(12,2),
    ozon_avg_delivery_time integer,
    ozon_attention_level character varying(20),
    ozon_impact_share numeric(8,4),
    delivery_cluster_id integer,
    delivery_cluster_name character varying(100),
    ozon_clusters_data jsonb
);


--
-- Name: COLUMN supply_recommendations.sales_trend; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.supply_recommendations.sales_trend IS 'Тренд продаж: growing, stable, declining';


--
-- Name: COLUMN supply_recommendations.sales_trend_percent; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.supply_recommendations.sales_trend_percent IS 'Изменение продаж в % (7d vs 14d)';


--
-- Name: COLUMN supply_recommendations.oos_date; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.supply_recommendations.oos_date IS 'Прогнозируемая дата OOS';


--
-- Name: COLUMN supply_recommendations.days_until_oos; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.supply_recommendations.days_until_oos IS 'Дней до OOS (с учётом товаров в пути)';


--
-- Name: COLUMN supply_recommendations.lost_revenue_daily; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.supply_recommendations.lost_revenue_daily IS 'Упущенная выручка в день при OOS (руб)';


--
-- Name: COLUMN supply_recommendations.lost_revenue_potential; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.supply_recommendations.lost_revenue_potential IS 'Потенциальная упущенная выручка до поставки (руб)';


--
-- Name: COLUMN supply_recommendations.sales_volatility; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.supply_recommendations.sales_volatility IS 'Волатильность продаж (коэф. вариации)';


--
-- Name: COLUMN supply_recommendations.safety_stock_dynamic; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.supply_recommendations.safety_stock_dynamic IS 'Динамический страховой запас (шт)';


--
-- Name: COLUMN supply_recommendations.supply_cost_estimate; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.supply_recommendations.supply_cost_estimate IS 'Оценка стоимости поставки (руб)';


--
-- Name: COLUMN supply_recommendations.expected_revenue; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.supply_recommendations.expected_revenue IS 'Ожидаемая выручка от поставки (руб)';


--
-- Name: COLUMN supply_recommendations.expected_profit; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.supply_recommendations.expected_profit IS 'Ожидаемая прибыль от поставки (руб)';


--
-- Name: COLUMN supply_recommendations.roi_percent; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.supply_recommendations.roi_percent IS 'ROI поставки в %';


--
-- Name: COLUMN supply_recommendations.price; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.supply_recommendations.price IS 'Цена продажи (руб)';


--
-- Name: COLUMN supply_recommendations.cost_price; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.supply_recommendations.cost_price IS 'Себестоимость (руб)';


--
-- Name: COLUMN supply_recommendations.margin_percent; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.supply_recommendations.margin_percent IS 'Маржинальность в %';


--
-- Name: COLUMN supply_recommendations.explanations; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.supply_recommendations.explanations IS 'Описания метрик для пользователя';


--
-- Name: COLUMN supply_recommendations.redemption_rate; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.supply_recommendations.redemption_rate IS 'Процент выкупа из unit_economics';


--
-- Name: COLUMN supply_recommendations.turnover_days_ue; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.supply_recommendations.turnover_days_ue IS 'Оборачиваемость в днях из unit_economics';


--
-- Name: COLUMN supply_recommendations.localization_index; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.supply_recommendations.localization_index IS 'Индекс локализации из unit_economics';


--
-- Name: COLUMN supply_recommendations.storage_cost; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.supply_recommendations.storage_cost IS 'Стоимость хранения из unit_economics';


--
-- Name: COLUMN supply_recommendations.delivery_cost; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.supply_recommendations.delivery_cost IS 'Стоимость доставки из unit_economics';


--
-- Name: COLUMN supply_recommendations.drr_percent; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.supply_recommendations.drr_percent IS 'ДРР процент из unit_economics';


--
-- Name: COLUMN supply_recommendations.ozon_recommended_supply; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.supply_recommendations.ozon_recommended_supply IS 'Рекомендуемая поставка от Ozon';


--
-- Name: COLUMN supply_recommendations.ozon_lost_profit; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.supply_recommendations.ozon_lost_profit IS 'Упущенная прибыль из-за долгой доставки';


--
-- Name: COLUMN supply_recommendations.ozon_avg_delivery_time; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.supply_recommendations.ozon_avg_delivery_time IS 'Среднее время доставки в часах';


--
-- Name: COLUMN supply_recommendations.ozon_attention_level; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.supply_recommendations.ozon_attention_level IS 'Уровень внимания: LOW, ATTENTION_MEDIUM, ATTENTION_HI';


--
-- Name: COLUMN supply_recommendations.ozon_impact_share; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.supply_recommendations.ozon_impact_share IS 'Доля влияния на общий показатель';


--
-- Name: COLUMN supply_recommendations.delivery_cluster_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.supply_recommendations.delivery_cluster_id IS 'ID кластера доставки Ozon';


--
-- Name: COLUMN supply_recommendations.delivery_cluster_name; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.supply_recommendations.delivery_cluster_name IS 'Название кластера доставки';


--
-- Name: supply_recommendations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.supply_recommendations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: supply_recommendations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.supply_recommendations_id_seq OWNED BY public.supply_recommendations.id;


--
-- Name: supply_settings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.supply_settings (
    id bigint NOT NULL,
    integration_id bigint NOT NULL,
    default_sales_window character varying(5) DEFAULT '14d'::character varying NOT NULL,
    target_days_a integer DEFAULT 21 NOT NULL,
    target_days_b integer DEFAULT 14 NOT NULL,
    target_days_c integer DEFAULT 7 NOT NULL,
    safety_stock_days integer DEFAULT 3 NOT NULL,
    safety_stock_percent numeric(5,2) DEFAULT '10'::numeric NOT NULL,
    safety_stock_mode character varying(10) DEFAULT 'days'::character varying NOT NULL,
    default_lead_time_days integer DEFAULT 3 NOT NULL,
    min_order_qty integer DEFAULT 1 NOT NULL,
    default_pack_multiple integer DEFAULT 1 NOT NULL,
    oos_risk_days integer DEFAULT 3 NOT NULL,
    overstock_days integer DEFAULT 60 NOT NULL,
    preferred_weekdays json,
    preferred_time_from time(0) without time zone,
    preferred_time_to time(0) without time zone,
    max_supplies_per_day integer DEFAULT 3 NOT NULL,
    max_items_per_supply integer DEFAULT 100 NOT NULL,
    max_qty_per_supply integer DEFAULT 10000 NOT NULL,
    auto_book_slot boolean DEFAULT false NOT NULL,
    auto_book_oos_threshold_days integer DEFAULT 2 NOT NULL,
    notify_no_slots boolean DEFAULT true NOT NULL,
    notify_no_slots_days integer DEFAULT 7 NOT NULL,
    notify_oos_risk boolean DEFAULT true NOT NULL,
    notify_stuck_supply boolean DEFAULT true NOT NULL,
    notify_stuck_hours integer DEFAULT 24 NOT NULL,
    notify_api_errors boolean DEFAULT true NOT NULL,
    notify_acceptance_issues boolean DEFAULT true NOT NULL,
    notification_channels json,
    notification_recipients json,
    excluded_skus json,
    excluded_categories json,
    restricted_skus json,
    is_active boolean DEFAULT true NOT NULL,
    custom_rules json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    locality_split_default boolean DEFAULT true NOT NULL,
    locality_min_confidence_default character varying(16) DEFAULT 'medium'::character varying NOT NULL,
    locality_max_split_clusters smallint DEFAULT '5'::smallint NOT NULL
);


--
-- Name: COLUMN supply_settings.locality_split_default; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.supply_settings.locality_split_default IS 'Автоматически разбивать новые планы по кластерам для Ozon';


--
-- Name: COLUMN supply_settings.locality_min_confidence_default; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.supply_settings.locality_min_confidence_default IS 'Минимальная уверенность рекомендаций для включения в split';


--
-- Name: COLUMN supply_settings.locality_max_split_clusters; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.supply_settings.locality_max_split_clusters IS 'Максимум target-кластеров на SKU при split';


--
-- Name: supply_settings_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.supply_settings_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: supply_settings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.supply_settings_id_seq OWNED BY public.supply_settings.id;


--
-- Name: sync_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sync_logs (
    id uuid NOT NULL,
    marketplace character varying(50) NOT NULL,
    sync_type character varying(255) NOT NULL,
    status character varying(255) NOT NULL,
    items_synced integer DEFAULT 0 NOT NULL,
    items_failed integer DEFAULT 0 NOT NULL,
    error_message text,
    metadata json,
    started_at timestamp(0) without time zone,
    completed_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    credentials text,
    integration_id bigint,
    CONSTRAINT sync_logs_status_check CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'running'::character varying, 'completed'::character varying, 'failed'::character varying])::text[]))),
    CONSTRAINT sync_logs_sync_type_check CHECK (((sync_type)::text = ANY ((ARRAY['products'::character varying, 'inventory'::character varying, 'sales'::character varying, 'commissions'::character varying])::text[])))
);


--
-- Name: timeslots_cache; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.timeslots_cache (
    id bigint NOT NULL,
    integration_id bigint NOT NULL,
    cluster_id character varying(50),
    cluster_name character varying(200),
    warehouse_id character varying(50) NOT NULL,
    warehouse_name character varying(200),
    draft_id character varying(50),
    timeslot_id character varying(50) NOT NULL,
    slot_date date NOT NULL,
    time_from time(0) without time zone NOT NULL,
    time_to time(0) without time zone NOT NULL,
    datetime_from timestamp(0) without time zone NOT NULL,
    datetime_to timestamp(0) without time zone NOT NULL,
    is_available boolean DEFAULT true NOT NULL,
    capacity integer,
    booked_count integer DEFAULT 0 NOT NULL,
    remaining_capacity integer,
    restrictions json,
    fetched_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    expires_at timestamp(0) without time zone NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: timeslots_cache_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.timeslots_cache_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: timeslots_cache_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.timeslots_cache_id_seq OWNED BY public.timeslots_cache.id;


--
-- Name: unit_economics; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.unit_economics (
    id bigint NOT NULL,
    product_name character varying(500),
    sku character varying(100) NOT NULL,
    marketplace character varying(50) NOT NULL,
    price numeric(12,2),
    cost_price numeric(12,2),
    sales_count integer,
    revenue numeric(14,2),
    total_costs numeric(14,2),
    gross_profit numeric(14,2),
    net_profit numeric(14,2),
    margin_percent numeric(12,2),
    roi_percent numeric(12,2),
    period_start date,
    period_end date,
    marketplace_data json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    integration_id bigint,
    commission_percent numeric(6,2),
    commission_amount numeric(12,2),
    delivery_cost numeric(12,2),
    return_cost numeric(12,2),
    last_mile_cost numeric(12,2),
    storage_cost numeric(12,2),
    storage_days integer,
    advertising_cost numeric(12,2),
    drr_percent numeric(6,2),
    redemption_rate numeric(6,2),
    orders_count integer,
    returns_count integer,
    acquiring_percent numeric(6,2),
    acquiring_amount numeric(12,2),
    packaging_cost numeric(12,2),
    fulfillment_type character varying(10),
    volume_liters numeric(10,2),
    volume_weight numeric(10,2),
    actual_weight numeric(10,2),
    processing_cost numeric(12,2),
    logistics_cost numeric(12,2),
    localization_index numeric(6,2),
    turnover_days integer,
    litrobonus numeric(12,2),
    return_logistics_cost numeric(12,2),
    own_delivery_cost numeric(12,2),
    ozon_compensation numeric(12,2),
    avg_delivery_time_hours integer DEFAULT 29,
    logistics_coefficient numeric(5,3) DEFAULT '1'::numeric,
    additional_commission_percent numeric(5,2) DEFAULT '0'::numeric,
    additional_commission_amount numeric(12,2),
    base_logistics_cost numeric(12,2),
    logistics_with_coefficient numeric(12,2),
    return_processing_cost numeric(12,2) DEFAULT '15'::numeric,
    logistics_per_unit numeric(12,2),
    last_mile_per_unit numeric(12,2) DEFAULT '25'::numeric,
    commission_per_unit numeric(12,2),
    acquiring_per_unit numeric(12,2),
    storage_per_unit numeric(12,2),
    total_costs_per_unit numeric(12,2),
    net_profit_per_unit numeric(12,2),
    redemption_source character varying(40),
    markup_percent numeric(12,2),
    expected_return_cost numeric(10,2),
    effective_logistics numeric(10,2),
    to_settlement_account numeric(12,2),
    our_share_percent numeric(5,2),
    tax_percent numeric(5,2),
    vat_percent numeric(5,2),
    tax_amount numeric(12,2),
    vat_amount numeric(12,2),
    drr_amount numeric(12,2),
    our_share_amount numeric(12,2),
    is_in_promotion boolean DEFAULT false NOT NULL,
    promotion_discount numeric(5,2),
    seller_price numeric(12,2),
    marketing_seller_price numeric(12,2),
    is_actual_scheme boolean DEFAULT false NOT NULL,
    length_mm numeric(10,2),
    width_mm numeric(10,2),
    height_mm numeric(10,2),
    weight_g numeric(10,2),
    markup_multiplier numeric(8,2),
    customer_price numeric(12,2),
    spp_percent numeric(6,2),
    spp_amount numeric(12,2),
    warehouse_coefficient_percent numeric(6,2),
    warehouse_coefficient_amount numeric(12,2),
    logistics_with_warehouse numeric(12,2),
    total_expenses_percent numeric(8,2),
    product_id uuid,
    tariff_version character varying(32),
    tariff_effective_from date,
    tariff_source character varying(32),
    route_key character varying(120),
    route_label character varying(255),
    is_local_sale boolean,
    non_local_markup_percent numeric(8,2),
    price_segment character varying(32),
    sales_fee_percent numeric(5,2),
    dimensions_observed_at timestamp(0) with time zone,
    redemption_observed_at timestamp(0) with time zone,
    acquiring_observed_at timestamp(0) with time zone,
    tax_base_price numeric(12,2),
    tax_base_source character varying(64)
);


--
-- Name: COLUMN unit_economics.redemption_source; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics.redemption_source IS 'Источник данных о выкупе: api, manual, fallback, default';


--
-- Name: COLUMN unit_economics.markup_percent; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics.markup_percent IS 'Наценка в % от себестоимости: (цена - себестоимость) / себестоимость * 100';


--
-- Name: COLUMN unit_economics.expected_return_cost; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics.expected_return_cost IS 'Ожидаемая стоимость возвратов с учётом % выкупа';


--
-- Name: COLUMN unit_economics.effective_logistics; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics.effective_logistics IS 'Эффективная логистика = прямая + ожидаемые возвраты';


--
-- Name: COLUMN unit_economics.to_settlement_account; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics.to_settlement_account IS 'На РС: Цена - Все затраты - РК (за единицу)';


--
-- Name: COLUMN unit_economics.our_share_percent; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics.our_share_percent IS 'Наша часть % (100 - комиссия МП - налоги)';


--
-- Name: COLUMN unit_economics.tax_percent; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics.tax_percent IS 'Налоги % (УСН/ОСНО)';


--
-- Name: COLUMN unit_economics.vat_percent; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics.vat_percent IS 'НДС %';


--
-- Name: COLUMN unit_economics.tax_amount; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics.tax_amount IS 'Сумма налога за единицу';


--
-- Name: COLUMN unit_economics.vat_amount; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics.vat_amount IS 'Сумма НДС за единицу';


--
-- Name: COLUMN unit_economics.drr_amount; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics.drr_amount IS 'РК сумма за единицу';


--
-- Name: COLUMN unit_economics.our_share_amount; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics.our_share_amount IS 'Наша часть сумма за единицу';


--
-- Name: COLUMN unit_economics.length_mm; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics.length_mm IS 'Длина, мм';


--
-- Name: COLUMN unit_economics.width_mm; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics.width_mm IS 'Ширина, мм';


--
-- Name: COLUMN unit_economics.height_mm; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics.height_mm IS 'Высота, мм';


--
-- Name: COLUMN unit_economics.weight_g; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics.weight_g IS 'Вес, г';


--
-- Name: COLUMN unit_economics.markup_multiplier; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics.markup_multiplier IS 'Наценка, x (множитель)';


--
-- Name: COLUMN unit_economics.customer_price; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics.customer_price IS 'Цена покупателя (с СПП)';


--
-- Name: COLUMN unit_economics.spp_percent; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics.spp_percent IS 'СПП, %';


--
-- Name: COLUMN unit_economics.spp_amount; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics.spp_amount IS 'СПП, ₽';


--
-- Name: COLUMN unit_economics.warehouse_coefficient_percent; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics.warehouse_coefficient_percent IS 'КС, %';


--
-- Name: COLUMN unit_economics.warehouse_coefficient_amount; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics.warehouse_coefficient_amount IS 'КС, ₽';


--
-- Name: COLUMN unit_economics.logistics_with_warehouse; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics.logistics_with_warehouse IS 'Логистика + КС, ₽';


--
-- Name: COLUMN unit_economics.total_expenses_percent; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics.total_expenses_percent IS 'Итоговый % расходов';


--
-- Name: unit_economics_cache; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.unit_economics_cache (
    id bigint NOT NULL,
    integration_id bigint NOT NULL,
    product_id uuid NOT NULL,
    sku character varying(255) NOT NULL,
    product_name character varying(500),
    marketplace character varying(50) DEFAULT 'ozon'::character varying NOT NULL,
    fulfillment_type character varying(20) DEFAULT 'FBO'::character varying NOT NULL,
    price numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    old_price numeric(12,2),
    sales_count integer DEFAULT 0 NOT NULL,
    volume_liters numeric(10,4),
    volume_weight numeric(10,4),
    depth numeric(10,2),
    width numeric(10,2),
    height numeric(10,2),
    weight numeric(10,2),
    commission_percent numeric(5,2) DEFAULT '0'::numeric NOT NULL,
    commission_amount numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    avg_delivery_time_hours integer DEFAULT 29 NOT NULL,
    logistics_coefficient numeric(5,3) DEFAULT '1'::numeric NOT NULL,
    additional_commission_percent numeric(5,2) DEFAULT '0'::numeric NOT NULL,
    base_logistics_cost numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    logistics_cost numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    last_mile_cost numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    processing_cost numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    storage_cost numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    redemption_rate numeric(5,2) DEFAULT '100'::numeric NOT NULL,
    return_logistics_cost numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    return_processing_cost numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    expected_return_cost numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    effective_logistics numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    acquiring_percent numeric(5,2) DEFAULT 1.5 NOT NULL,
    acquiring_amount numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    cost_price numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    drr_percent numeric(5,2) DEFAULT '0'::numeric NOT NULL,
    drr_amount numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    our_share_percent numeric(5,2) DEFAULT '0'::numeric NOT NULL,
    our_share_amount numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    tax_percent numeric(5,2) DEFAULT '6'::numeric NOT NULL,
    tax_amount numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    vat_percent numeric(5,2) DEFAULT '0'::numeric NOT NULL,
    vat_amount numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    revenue numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    total_costs numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    gross_profit numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    net_profit numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    to_settlement_account numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    margin_percent numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    markup_percent numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    roi_percent numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    calculated_at timestamp(0) without time zone,
    data_version integer DEFAULT 1 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    is_in_promotion boolean DEFAULT false NOT NULL,
    promotion_discount numeric(5,2) DEFAULT '0'::numeric NOT NULL,
    redemption_source character varying(255),
    orders_count integer,
    returns_count integer,
    packaging_cost numeric(12,2),
    tariff_version character varying(64),
    tariff_effective_from date,
    tariff_source character varying(64),
    route_key character varying(120),
    route_label character varying(255),
    is_local_sale boolean,
    non_local_markup_percent numeric(8,2),
    price_segment character varying(32),
    sales_fee_percent numeric(5,2),
    marketplace_data json,
    return_fee numeric(12,2),
    CONSTRAINT unit_economics_cache_fulfillment_type_check CHECK (((fulfillment_type)::text = ANY ((ARRAY['FBO'::character varying, 'FBS'::character varying, 'RFBS'::character varying, 'EXPRESS'::character varying, 'FBY'::character varying, 'DBS'::character varying, 'EDBS'::character varying, 'DBW'::character varying, 'MIXED'::character varying])::text[])))
);


--
-- Name: COLUMN unit_economics_cache.price; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_cache.price IS 'Цена продажи';


--
-- Name: COLUMN unit_economics_cache.old_price; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_cache.old_price IS 'Старая цена';


--
-- Name: COLUMN unit_economics_cache.sales_count; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_cache.sales_count IS 'Продажи за 30 дней';


--
-- Name: COLUMN unit_economics_cache.volume_liters; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_cache.volume_liters IS 'Объём в литрах';


--
-- Name: COLUMN unit_economics_cache.volume_weight; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_cache.volume_weight IS 'Объёмный вес';


--
-- Name: COLUMN unit_economics_cache.depth; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_cache.depth IS 'Длина мм';


--
-- Name: COLUMN unit_economics_cache.width; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_cache.width IS 'Ширина мм';


--
-- Name: COLUMN unit_economics_cache.height; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_cache.height IS 'Высота мм';


--
-- Name: COLUMN unit_economics_cache.weight; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_cache.weight IS 'Вес г';


--
-- Name: COLUMN unit_economics_cache.commission_percent; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_cache.commission_percent IS '% комиссии';


--
-- Name: COLUMN unit_economics_cache.commission_amount; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_cache.commission_amount IS 'Сумма комиссии за шт';


--
-- Name: COLUMN unit_economics_cache.avg_delivery_time_hours; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_cache.avg_delivery_time_hours IS 'Среднее время доставки';


--
-- Name: COLUMN unit_economics_cache.logistics_coefficient; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_cache.logistics_coefficient IS 'Коэффициент времени';


--
-- Name: COLUMN unit_economics_cache.additional_commission_percent; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_cache.additional_commission_percent IS 'Доп. % от цены';


--
-- Name: COLUMN unit_economics_cache.base_logistics_cost; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_cache.base_logistics_cost IS 'Базовый тариф логистики';


--
-- Name: COLUMN unit_economics_cache.logistics_cost; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_cache.logistics_cost IS 'Логистика за шт';


--
-- Name: COLUMN unit_economics_cache.last_mile_cost; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_cache.last_mile_cost IS 'Последняя миля';


--
-- Name: COLUMN unit_economics_cache.processing_cost; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_cache.processing_cost IS 'Обработка отправления (FBS)';


--
-- Name: COLUMN unit_economics_cache.storage_cost; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_cache.storage_cost IS 'Хранение';


--
-- Name: COLUMN unit_economics_cache.redemption_rate; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_cache.redemption_rate IS '% выкупа';


--
-- Name: COLUMN unit_economics_cache.return_logistics_cost; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_cache.return_logistics_cost IS 'Обратная логистика';


--
-- Name: COLUMN unit_economics_cache.return_processing_cost; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_cache.return_processing_cost IS 'Обработка возврата';


--
-- Name: COLUMN unit_economics_cache.expected_return_cost; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_cache.expected_return_cost IS 'Ожидаемые возвраты за шт';


--
-- Name: COLUMN unit_economics_cache.effective_logistics; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_cache.effective_logistics IS 'Эффективная логистика';


--
-- Name: COLUMN unit_economics_cache.acquiring_percent; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_cache.acquiring_percent IS '% эквайринга';


--
-- Name: COLUMN unit_economics_cache.acquiring_amount; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_cache.acquiring_amount IS 'Сумма эквайринга';


--
-- Name: COLUMN unit_economics_cache.cost_price; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_cache.cost_price IS 'Себестоимость';


--
-- Name: COLUMN unit_economics_cache.drr_percent; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_cache.drr_percent IS 'РК %';


--
-- Name: COLUMN unit_economics_cache.drr_amount; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_cache.drr_amount IS 'РК сумма';


--
-- Name: COLUMN unit_economics_cache.our_share_percent; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_cache.our_share_percent IS 'Наша часть %';


--
-- Name: COLUMN unit_economics_cache.our_share_amount; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_cache.our_share_amount IS 'Наша часть сумма';


--
-- Name: COLUMN unit_economics_cache.tax_percent; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_cache.tax_percent IS 'Налог %';


--
-- Name: COLUMN unit_economics_cache.tax_amount; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_cache.tax_amount IS 'Налог сумма';


--
-- Name: COLUMN unit_economics_cache.vat_percent; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_cache.vat_percent IS 'НДС %';


--
-- Name: COLUMN unit_economics_cache.vat_amount; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_cache.vat_amount IS 'НДС сумма';


--
-- Name: COLUMN unit_economics_cache.revenue; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_cache.revenue IS 'Выручка';


--
-- Name: COLUMN unit_economics_cache.total_costs; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_cache.total_costs IS 'Все затраты';


--
-- Name: COLUMN unit_economics_cache.gross_profit; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_cache.gross_profit IS 'Валовая прибыль';


--
-- Name: COLUMN unit_economics_cache.net_profit; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_cache.net_profit IS 'Чистая прибыль за шт';


--
-- Name: COLUMN unit_economics_cache.to_settlement_account; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_cache.to_settlement_account IS 'На расчётный счёт';


--
-- Name: COLUMN unit_economics_cache.margin_percent; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_cache.margin_percent IS 'Маржа %';


--
-- Name: COLUMN unit_economics_cache.markup_percent; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_cache.markup_percent IS 'Наценка %';


--
-- Name: COLUMN unit_economics_cache.roi_percent; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_cache.roi_percent IS 'ROI %';


--
-- Name: COLUMN unit_economics_cache.calculated_at; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_cache.calculated_at IS 'Время расчёта';


--
-- Name: COLUMN unit_economics_cache.data_version; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_cache.data_version IS 'Версия данных';


--
-- Name: unit_economics_cache_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.unit_economics_cache_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: unit_economics_cache_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.unit_economics_cache_id_seq OWNED BY public.unit_economics_cache.id;


--
-- Name: unit_economics_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.unit_economics_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: unit_economics_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.unit_economics_id_seq OWNED BY public.unit_economics.id;


--
-- Name: unit_economics_settings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.unit_economics_settings (
    id bigint NOT NULL,
    integration_id bigint NOT NULL,
    sku character varying(255) NOT NULL,
    cost_price numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    drr_percent numeric(5,2) DEFAULT '0'::numeric NOT NULL,
    our_share_percent numeric(5,2) DEFAULT '0'::numeric NOT NULL,
    tax_percent numeric(5,2) DEFAULT '6'::numeric NOT NULL,
    vat_percent numeric(5,2) DEFAULT '0'::numeric NOT NULL,
    redemption_rate_override numeric(5,2),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    length_mm numeric(10,2),
    width_mm numeric(10,2),
    height_mm numeric(10,2),
    weight_g numeric(10,2),
    spp_percent numeric(5,2),
    is_select_only boolean DEFAULT false NOT NULL,
    is_size_restricted boolean DEFAULT false NOT NULL,
    return_fee numeric(12,2),
    warehouse_coef_percent_override numeric(6,2),
    acceptance_cost numeric(12,2)
);


--
-- Name: COLUMN unit_economics_settings.cost_price; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_settings.cost_price IS 'Себестоимость';


--
-- Name: COLUMN unit_economics_settings.drr_percent; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_settings.drr_percent IS 'РК % (ДРР)';


--
-- Name: COLUMN unit_economics_settings.our_share_percent; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_settings.our_share_percent IS 'Наша часть %';


--
-- Name: COLUMN unit_economics_settings.tax_percent; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_settings.tax_percent IS 'Налог % (УСН)';


--
-- Name: COLUMN unit_economics_settings.vat_percent; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_settings.vat_percent IS 'НДС %';


--
-- Name: COLUMN unit_economics_settings.redemption_rate_override; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_settings.redemption_rate_override IS 'Переопределение % выкупа (если null - берётся из API)';


--
-- Name: COLUMN unit_economics_settings.is_select_only; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_settings.is_select_only IS 'Товар продаётся только на Select — Ozon не применяет наценку за нелокальную продажу';


--
-- Name: COLUMN unit_economics_settings.is_size_restricted; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_settings.is_size_restricted IS 'Крупногабарит/ювелирка — запрет размещения в локальном складе, наценка не применяется';


--
-- Name: COLUMN unit_economics_settings.warehouse_coef_percent_override; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_settings.warehouse_coef_percent_override IS 'Ручной КС WB, % (100 = 1.0). null = авто';


--
-- Name: COLUMN unit_economics_settings.acceptance_cost; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.unit_economics_settings.acceptance_cost IS 'Платная приёмка на единицу, ₽ (ручной ввод)';


--
-- Name: unit_economics_settings_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.unit_economics_settings_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: unit_economics_settings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.unit_economics_settings_id_seq OWNED BY public.unit_economics_settings.id;


--
-- Name: users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.users (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    email character varying(255) NOT NULL,
    email_verified_at timestamp(0) without time zone,
    password character varying(255) NOT NULL,
    remember_token character varying(100),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: users_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.users_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: users_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.users_id_seq OWNED BY public.users.id;


--
-- Name: uzum_extension_commands; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.uzum_extension_commands (
    id uuid NOT NULL,
    integration_id bigint NOT NULL,
    method character varying(8) DEFAULT 'GET'::character varying NOT NULL,
    path text NOT NULL,
    body json,
    status character varying(16) DEFAULT 'pending'::character varying NOT NULL,
    http_status integer,
    response json,
    error text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    command_key character varying(80),
    expires_at timestamp(0) without time zone
);


--
-- Name: uzum_extension_snapshots; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.uzum_extension_snapshots (
    id bigint NOT NULL,
    integration_id bigint NOT NULL,
    shop_id character varying(100),
    payload_type character varying(30) NOT NULL,
    raw_payload json NOT NULL,
    items_count integer DEFAULT 0 NOT NULL,
    accepted_count integer DEFAULT 0 NOT NULL,
    rejected_count integer DEFAULT 0 NOT NULL,
    status character varying(20) DEFAULT 'ok'::character varying NOT NULL,
    error_message text,
    extension_version character varying(30),
    extractor_version character varying(30),
    collected_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    payload_hash character varying(64)
);


--
-- Name: uzum_extension_snapshots_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.uzum_extension_snapshots_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: uzum_extension_snapshots_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.uzum_extension_snapshots_id_seq OWNED BY public.uzum_extension_snapshots.id;


--
-- Name: warehouse_slots; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.warehouse_slots (
    id uuid NOT NULL,
    marketplace character varying(20) NOT NULL,
    warehouse_id character varying(100) NOT NULL,
    warehouse_name character varying(255),
    external_slot_id character varying(255),
    date date NOT NULL,
    time_from time(0) without time zone NOT NULL,
    time_to time(0) without time zone NOT NULL,
    coefficient numeric(5,3),
    is_available boolean DEFAULT true NOT NULL,
    capacity integer,
    capacity_used integer DEFAULT 0 NOT NULL,
    boxes_limit integer,
    pallets_limit integer,
    booked_by_shipment_id uuid,
    booked_at timestamp(0) without time zone,
    synced_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    from_datetime timestamp(0) without time zone,
    to_datetime timestamp(0) without time zone,
    allow_unload boolean DEFAULT false NOT NULL,
    box_type_id integer,
    is_sorting_center boolean DEFAULT false NOT NULL,
    storage_coefficient numeric(8,3),
    delivery_coefficient numeric(8,3),
    macrolocal_cluster_id character varying(50),
    cluster_name character varying(200),
    booked_by_supply_id bigint
);


--
-- Name: COLUMN warehouse_slots.macrolocal_cluster_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.warehouse_slots.macrolocal_cluster_id IS 'ID макролокального кластера Ozon';


--
-- Name: COLUMN warehouse_slots.cluster_name; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.warehouse_slots.cluster_name IS 'Название кластера Ozon';


--
-- Name: wb_barcode_costs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.wb_barcode_costs (
    id bigint NOT NULL,
    integration_id bigint NOT NULL,
    nm_id character varying(255) NOT NULL,
    barcode character varying(255) NOT NULL,
    chrt_id character varying(255),
    size_name character varying(255),
    cost_price numeric(10,2),
    created_at timestamp without time zone,
    updated_at timestamp without time zone
);


--
-- Name: wb_barcode_costs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.wb_barcode_costs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: wb_barcode_costs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.wb_barcode_costs_id_seq OWNED BY public.wb_barcode_costs.id;


--
-- Name: wb_webhook_configs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.wb_webhook_configs (
    id bigint NOT NULL,
    integration_id bigint NOT NULL,
    webhook_url character varying(500),
    secret_key character varying(255),
    is_active boolean DEFAULT false,
    last_event_at timestamp without time zone,
    events_count bigint DEFAULT 0,
    created_at timestamp without time zone,
    updated_at timestamp without time zone
);


--
-- Name: wb_webhook_configs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.wb_webhook_configs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: wb_webhook_configs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.wb_webhook_configs_id_seq OWNED BY public.wb_webhook_configs.id;


--
-- Name: wildberries_spp_snapshots; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.wildberries_spp_snapshots (
    id bigint NOT NULL,
    integration_id bigint NOT NULL,
    sku character varying(100) NOT NULL,
    nm_id character varying(64),
    spp_percent numeric(7,2) NOT NULL,
    seller_price numeric(14,2),
    customer_price numeric(14,2),
    source character varying(32) NOT NULL,
    observed_at timestamp(0) without time zone NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: wildberries_spp_snapshots_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.wildberries_spp_snapshots_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: wildberries_spp_snapshots_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.wildberries_spp_snapshots_id_seq OWNED BY public.wildberries_spp_snapshots.id;


--
-- Name: wildberries_spp_sync_states; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.wildberries_spp_sync_states (
    id bigint NOT NULL,
    integration_id bigint NOT NULL,
    status character varying(20) DEFAULT 'idle'::character varying NOT NULL,
    attempt integer DEFAULT 0 NOT NULL,
    updated_count integer DEFAULT 0 NOT NULL,
    total_count integer DEFAULT 0 NOT NULL,
    preserved_count integer DEFAULT 0 NOT NULL,
    source character varying(32),
    source_counts json,
    message text,
    last_error text,
    requested_at timestamp(0) without time zone,
    started_at timestamp(0) without time zone,
    finished_at timestamp(0) without time zone,
    last_success_at timestamp(0) without time zone,
    retry_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: wildberries_spp_sync_states_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.wildberries_spp_sync_states_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: wildberries_spp_sync_states_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.wildberries_spp_sync_states_id_seq OWNED BY public.wildberries_spp_sync_states.id;


--
-- Name: wildberries_tariff_snapshots; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.wildberries_tariff_snapshots (
    id bigint NOT NULL,
    integration_id bigint,
    marketplace character varying(50) DEFAULT 'wildberries'::character varying NOT NULL,
    tariff_type character varying(50) NOT NULL,
    effective_date date,
    warehouse_id character varying(100) DEFAULT ''::character varying NOT NULL,
    warehouse_name character varying(255),
    subject_id character varying(100) DEFAULT ''::character varying NOT NULL,
    subject_name character varying(255),
    scheme character varying(50) DEFAULT ''::character varying NOT NULL,
    payload json,
    fetched_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: wildberries_tariff_snapshots_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.wildberries_tariff_snapshots_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: wildberries_tariff_snapshots_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.wildberries_tariff_snapshots_id_seq OWNED BY public.wildberries_tariff_snapshots.id;


--
-- Name: auto_supply_constraint_files id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auto_supply_constraint_files ALTER COLUMN id SET DEFAULT nextval('public.auto_supply_constraint_files_id_seq'::regclass);


--
-- Name: auto_supply_plan_adjustments id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auto_supply_plan_adjustments ALTER COLUMN id SET DEFAULT nextval('public.auto_supply_plan_adjustments_id_seq'::regclass);


--
-- Name: auto_supply_plan_lines id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auto_supply_plan_lines ALTER COLUMN id SET DEFAULT nextval('public.auto_supply_plan_lines_id_seq'::regclass);


--
-- Name: failed_jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs ALTER COLUMN id SET DEFAULT nextval('public.failed_jobs_id_seq'::regclass);


--
-- Name: integrations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.integrations ALTER COLUMN id SET DEFAULT nextval('public.integrations_id_seq'::regclass);


--
-- Name: jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs ALTER COLUMN id SET DEFAULT nextval('public.jobs_id_seq'::regclass);


--
-- Name: locality_metrics_cluster_daily id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.locality_metrics_cluster_daily ALTER COLUMN id SET DEFAULT nextval('public.locality_metrics_cluster_daily_id_seq'::regclass);


--
-- Name: locality_metrics_daily id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.locality_metrics_daily ALTER COLUMN id SET DEFAULT nextval('public.locality_metrics_daily_id_seq'::regclass);


--
-- Name: locality_recommendations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.locality_recommendations ALTER COLUMN id SET DEFAULT nextval('public.locality_recommendations_id_seq'::regclass);


--
-- Name: locality_reconciliation_log id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.locality_reconciliation_log ALTER COLUMN id SET DEFAULT nextval('public.locality_reconciliation_log_id_seq'::regclass);


--
-- Name: marketplace_constraint_snapshots id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.marketplace_constraint_snapshots ALTER COLUMN id SET DEFAULT nextval('public.marketplace_constraint_snapshots_id_seq'::regclass);


--
-- Name: migrations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations ALTER COLUMN id SET DEFAULT nextval('public.migrations_id_seq'::regclass);


--
-- Name: ozon_ad_stats id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ozon_ad_stats ALTER COLUMN id SET DEFAULT nextval('public.ozon_ad_stats_id_seq'::regclass);


--
-- Name: ozon_finance_transactions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ozon_finance_transactions ALTER COLUMN id SET DEFAULT nextval('public.ozon_finance_transactions_id_seq'::regclass);


--
-- Name: ozon_order_unit_economics id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ozon_order_unit_economics ALTER COLUMN id SET DEFAULT nextval('public.ozon_order_unit_economics_id_seq'::regclass);


--
-- Name: ozon_sku_delivery_profiles id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ozon_sku_delivery_profiles ALTER COLUMN id SET DEFAULT nextval('public.ozon_sku_delivery_profiles_id_seq'::regclass);


--
-- Name: ozon_supply_fixations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ozon_supply_fixations ALTER COLUMN id SET DEFAULT nextval('public.ozon_supply_fixations_id_seq'::regclass);


--
-- Name: ozon_warehouse_clusters id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ozon_warehouse_clusters ALTER COLUMN id SET DEFAULT nextval('public.ozon_warehouse_clusters_id_seq'::regclass);


--
-- Name: personal_access_tokens id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens ALTER COLUMN id SET DEFAULT nextval('public.personal_access_tokens_id_seq'::regclass);


--
-- Name: plan_line_evaluations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.plan_line_evaluations ALTER COLUMN id SET DEFAULT nextval('public.plan_line_evaluations_id_seq'::regclass);


--
-- Name: seller_warehouse_stocks id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seller_warehouse_stocks ALTER COLUMN id SET DEFAULT nextval('public.seller_warehouse_stocks_id_seq'::regclass);


--
-- Name: supplies id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supplies ALTER COLUMN id SET DEFAULT nextval('public.supplies_id_seq'::regclass);


--
-- Name: supply_analytics id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supply_analytics ALTER COLUMN id SET DEFAULT nextval('public.supply_analytics_id_seq'::regclass);


--
-- Name: supply_documents id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supply_documents ALTER COLUMN id SET DEFAULT nextval('public.supply_documents_id_seq'::regclass);


--
-- Name: supply_events id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supply_events ALTER COLUMN id SET DEFAULT nextval('public.supply_events_id_seq'::regclass);


--
-- Name: supply_items id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supply_items ALTER COLUMN id SET DEFAULT nextval('public.supply_items_id_seq'::regclass);


--
-- Name: supply_package_items id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supply_package_items ALTER COLUMN id SET DEFAULT nextval('public.supply_package_items_id_seq'::regclass);


--
-- Name: supply_packages id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supply_packages ALTER COLUMN id SET DEFAULT nextval('public.supply_packages_id_seq'::regclass);


--
-- Name: supply_recommendations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supply_recommendations ALTER COLUMN id SET DEFAULT nextval('public.supply_recommendations_id_seq'::regclass);


--
-- Name: supply_settings id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supply_settings ALTER COLUMN id SET DEFAULT nextval('public.supply_settings_id_seq'::regclass);


--
-- Name: timeslots_cache id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.timeslots_cache ALTER COLUMN id SET DEFAULT nextval('public.timeslots_cache_id_seq'::regclass);


--
-- Name: unit_economics id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.unit_economics ALTER COLUMN id SET DEFAULT nextval('public.unit_economics_id_seq'::regclass);


--
-- Name: unit_economics_cache id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.unit_economics_cache ALTER COLUMN id SET DEFAULT nextval('public.unit_economics_cache_id_seq'::regclass);


--
-- Name: unit_economics_settings id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.unit_economics_settings ALTER COLUMN id SET DEFAULT nextval('public.unit_economics_settings_id_seq'::regclass);


--
-- Name: users id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users ALTER COLUMN id SET DEFAULT nextval('public.users_id_seq'::regclass);


--
-- Name: uzum_extension_snapshots id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.uzum_extension_snapshots ALTER COLUMN id SET DEFAULT nextval('public.uzum_extension_snapshots_id_seq'::regclass);


--
-- Name: wb_barcode_costs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wb_barcode_costs ALTER COLUMN id SET DEFAULT nextval('public.wb_barcode_costs_id_seq'::regclass);


--
-- Name: wb_webhook_configs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wb_webhook_configs ALTER COLUMN id SET DEFAULT nextval('public.wb_webhook_configs_id_seq'::regclass);


--
-- Name: wildberries_spp_snapshots id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wildberries_spp_snapshots ALTER COLUMN id SET DEFAULT nextval('public.wildberries_spp_snapshots_id_seq'::regclass);


--
-- Name: wildberries_spp_sync_states id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wildberries_spp_sync_states ALTER COLUMN id SET DEFAULT nextval('public.wildberries_spp_sync_states_id_seq'::regclass);


--
-- Name: wildberries_tariff_snapshots id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wildberries_tariff_snapshots ALTER COLUMN id SET DEFAULT nextval('public.wildberries_tariff_snapshots_id_seq'::regclass);


--
-- Name: auto_supply_constraint_files asp_constraint_files_hash_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auto_supply_constraint_files
    ADD CONSTRAINT asp_constraint_files_hash_unique UNIQUE (integration_id, file_hash);


--
-- Name: auto_supply_plan_executions aspe_plan_idempotency_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auto_supply_plan_executions
    ADD CONSTRAINT aspe_plan_idempotency_unique UNIQUE (auto_supply_plan_id, idempotency_key);


--
-- Name: audit_logs audit_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audit_logs
    ADD CONSTRAINT audit_logs_pkey PRIMARY KEY (id);


--
-- Name: auto_supply_constraint_files auto_supply_constraint_files_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auto_supply_constraint_files
    ADD CONSTRAINT auto_supply_constraint_files_pkey PRIMARY KEY (id);


--
-- Name: auto_supply_plan_adjustments auto_supply_plan_adjustments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auto_supply_plan_adjustments
    ADD CONSTRAINT auto_supply_plan_adjustments_pkey PRIMARY KEY (id);


--
-- Name: auto_supply_plan_executions auto_supply_plan_executions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auto_supply_plan_executions
    ADD CONSTRAINT auto_supply_plan_executions_pkey PRIMARY KEY (id);


--
-- Name: auto_supply_plan_lines auto_supply_plan_lines_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auto_supply_plan_lines
    ADD CONSTRAINT auto_supply_plan_lines_pkey PRIMARY KEY (id);


--
-- Name: auto_supply_plans auto_supply_plans_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auto_supply_plans
    ADD CONSTRAINT auto_supply_plans_pkey PRIMARY KEY (id);


--
-- Name: cache_locks cache_locks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache_locks
    ADD CONSTRAINT cache_locks_pkey PRIMARY KEY (key);


--
-- Name: cache cache_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache
    ADD CONSTRAINT cache_pkey PRIMARY KEY (key);


--
-- Name: failed_jobs failed_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_uuid_unique UNIQUE (uuid);


--
-- Name: integrations integrations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.integrations
    ADD CONSTRAINT integrations_pkey PRIMARY KEY (id);


--
-- Name: inventory_alerts inventory_alerts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_alerts
    ADD CONSTRAINT inventory_alerts_pkey PRIMARY KEY (id);


--
-- Name: inventory_history inventory_history_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_history
    ADD CONSTRAINT inventory_history_pkey PRIMARY KEY (id);


--
-- Name: inventory_history inventory_history_sku_warehouse_id_date_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_history
    ADD CONSTRAINT inventory_history_sku_warehouse_id_date_unique UNIQUE (sku, warehouse_id, date);


--
-- Name: inventory_warehouses inventory_warehouses_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_warehouses
    ADD CONSTRAINT inventory_warehouses_pkey PRIMARY KEY (id);


--
-- Name: job_batches job_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.job_batches
    ADD CONSTRAINT job_batches_pkey PRIMARY KEY (id);


--
-- Name: jobs jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs
    ADD CONSTRAINT jobs_pkey PRIMARY KEY (id);


--
-- Name: locality_metrics_cluster_daily locality_metrics_cluster_daily_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.locality_metrics_cluster_daily
    ADD CONSTRAINT locality_metrics_cluster_daily_pkey PRIMARY KEY (id);


--
-- Name: locality_metrics_cluster_daily locality_metrics_cluster_daily_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.locality_metrics_cluster_daily
    ADD CONSTRAINT locality_metrics_cluster_daily_unique UNIQUE (integration_id, destination_cluster_name, snapshot_date, period_days);


--
-- Name: locality_metrics_daily locality_metrics_daily_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.locality_metrics_daily
    ADD CONSTRAINT locality_metrics_daily_pkey PRIMARY KEY (id);


--
-- Name: locality_metrics_daily locality_metrics_daily_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.locality_metrics_daily
    ADD CONSTRAINT locality_metrics_daily_unique UNIQUE (integration_id, sku, snapshot_date, period_days);


--
-- Name: locality_recommendations locality_recommendations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.locality_recommendations
    ADD CONSTRAINT locality_recommendations_pkey PRIMARY KEY (id);


--
-- Name: locality_reconciliation_log locality_reconciliation_log_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.locality_reconciliation_log
    ADD CONSTRAINT locality_reconciliation_log_pkey PRIMARY KEY (id);


--
-- Name: locality_recommendations locality_recs_sku_cluster_cohort_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.locality_recommendations
    ADD CONSTRAINT locality_recs_sku_cluster_cohort_unique UNIQUE (integration_id, sku, target_cluster_id, cohort_id);


--
-- Name: marketplace_constraint_snapshots marketplace_constraint_snapshots_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.marketplace_constraint_snapshots
    ADD CONSTRAINT marketplace_constraint_snapshots_pkey PRIMARY KEY (id);


--
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- Name: marketplace_constraint_snapshots mp_constraint_snap_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.marketplace_constraint_snapshots
    ADD CONSTRAINT mp_constraint_snap_unique UNIQUE (integration_id, marketplace);


--
-- Name: ozon_ad_stats ozon_ad_stats_int_period_uq; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ozon_ad_stats
    ADD CONSTRAINT ozon_ad_stats_int_period_uq UNIQUE (integration_id, date_from, date_to);


--
-- Name: ozon_ad_stats ozon_ad_stats_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ozon_ad_stats
    ADD CONSTRAINT ozon_ad_stats_pkey PRIMARY KEY (id);


--
-- Name: ozon_sku_delivery_profiles ozon_delivery_profiles_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ozon_sku_delivery_profiles
    ADD CONSTRAINT ozon_delivery_profiles_unique UNIQUE (integration_id, sku, scheme);


--
-- Name: ozon_finance_transactions ozon_finance_transactions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ozon_finance_transactions
    ADD CONSTRAINT ozon_finance_transactions_pkey PRIMARY KEY (id);


--
-- Name: ozon_finance_transactions ozon_finance_txn_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ozon_finance_transactions
    ADD CONSTRAINT ozon_finance_txn_unique UNIQUE (integration_id, operation_id);


--
-- Name: ozon_order_reports ozon_order_reports_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ozon_order_reports
    ADD CONSTRAINT ozon_order_reports_pkey PRIMARY KEY (id);


--
-- Name: ozon_order_unit_economics ozon_order_unit_economics_business_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ozon_order_unit_economics
    ADD CONSTRAINT ozon_order_unit_economics_business_key_unique UNIQUE (integration_id, posting_number, sku, offer_id);


--
-- Name: ozon_order_unit_economics ozon_order_unit_economics_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ozon_order_unit_economics
    ADD CONSTRAINT ozon_order_unit_economics_pkey PRIMARY KEY (id);


--
-- Name: ozon_sku_delivery_profiles ozon_sku_delivery_profiles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ozon_sku_delivery_profiles
    ADD CONSTRAINT ozon_sku_delivery_profiles_pkey PRIMARY KEY (id);


--
-- Name: ozon_supply_fixations ozon_supply_fixations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ozon_supply_fixations
    ADD CONSTRAINT ozon_supply_fixations_pkey PRIMARY KEY (id);


--
-- Name: ozon_supply_fixations ozon_supply_fixations_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ozon_supply_fixations
    ADD CONSTRAINT ozon_supply_fixations_unique UNIQUE (integration_id, supply_id, sku);


--
-- Name: ozon_warehouse_clusters ozon_warehouse_clusters_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ozon_warehouse_clusters
    ADD CONSTRAINT ozon_warehouse_clusters_pkey PRIMARY KEY (id);


--
-- Name: ozon_warehouse_clusters ozon_warehouse_clusters_warehouse_name_normalized_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ozon_warehouse_clusters
    ADD CONSTRAINT ozon_warehouse_clusters_warehouse_name_normalized_unique UNIQUE (warehouse_name_normalized);


--
-- Name: ozon_warehouse_sales ozon_warehouse_sales_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ozon_warehouse_sales
    ADD CONSTRAINT ozon_warehouse_sales_pkey PRIMARY KEY (id);


--
-- Name: password_reset_tokens password_reset_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.password_reset_tokens
    ADD CONSTRAINT password_reset_tokens_pkey PRIMARY KEY (email);


--
-- Name: personal_access_tokens personal_access_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_pkey PRIMARY KEY (id);


--
-- Name: personal_access_tokens personal_access_tokens_token_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_token_unique UNIQUE (token);


--
-- Name: plan_line_evaluations plan_line_eval_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.plan_line_evaluations
    ADD CONSTRAINT plan_line_eval_unique UNIQUE (auto_supply_plan_line_id);


--
-- Name: plan_line_evaluations plan_line_evaluations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.plan_line_evaluations
    ADD CONSTRAINT plan_line_evaluations_pkey PRIMARY KEY (id);


--
-- Name: planning_fact_snapshots planning_fact_snapshots_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.planning_fact_snapshots
    ADD CONSTRAINT planning_fact_snapshots_pkey PRIMARY KEY (id);


--
-- Name: posting_items posting_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.posting_items
    ADD CONSTRAINT posting_items_pkey PRIMARY KEY (id);


--
-- Name: postings postings_integration_id_posting_number_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.postings
    ADD CONSTRAINT postings_integration_id_posting_number_unique UNIQUE (integration_id, posting_number);


--
-- Name: postings postings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.postings
    ADD CONSTRAINT postings_pkey PRIMARY KEY (id);


--
-- Name: products products_marketplace_integration_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT products_marketplace_integration_unique UNIQUE (marketplace, marketplace_id, integration_id);


--
-- Name: products products_marketplace_sku_integration_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT products_marketplace_sku_integration_unique UNIQUE (marketplace, sku, integration_id);


--
-- Name: products products_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT products_pkey PRIMARY KEY (id);


--
-- Name: seller_warehouse_stocks seller_warehouse_stocks_integration_id_sku_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seller_warehouse_stocks
    ADD CONSTRAINT seller_warehouse_stocks_integration_id_sku_unique UNIQUE (integration_id, sku);


--
-- Name: seller_warehouse_stocks seller_warehouse_stocks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seller_warehouse_stocks
    ADD CONSTRAINT seller_warehouse_stocks_pkey PRIMARY KEY (id);


--
-- Name: sessions sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (id);


--
-- Name: shipment_items shipment_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.shipment_items
    ADD CONSTRAINT shipment_items_pkey PRIMARY KEY (id);


--
-- Name: shipment_recommendations shipment_recommendations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.shipment_recommendations
    ADD CONSTRAINT shipment_recommendations_pkey PRIMARY KEY (id);


--
-- Name: shipments shipments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.shipments
    ADD CONSTRAINT shipments_pkey PRIMARY KEY (id);


--
-- Name: suppliers suppliers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.suppliers
    ADD CONSTRAINT suppliers_pkey PRIMARY KEY (id);


--
-- Name: supplies supplies_auto_plan_cluster_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supplies
    ADD CONSTRAINT supplies_auto_plan_cluster_unique UNIQUE (auto_supply_plan_id, cluster_id);


--
-- Name: supplies supplies_crm_number_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supplies
    ADD CONSTRAINT supplies_crm_number_unique UNIQUE (crm_number);


--
-- Name: supplies supplies_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supplies
    ADD CONSTRAINT supplies_pkey PRIMARY KEY (id);


--
-- Name: supply_analytics supply_analytics_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supply_analytics
    ADD CONSTRAINT supply_analytics_pkey PRIMARY KEY (id);


--
-- Name: supply_documents supply_documents_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supply_documents
    ADD CONSTRAINT supply_documents_pkey PRIMARY KEY (id);


--
-- Name: supply_events supply_events_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supply_events
    ADD CONSTRAINT supply_events_pkey PRIMARY KEY (id);


--
-- Name: supply_items supply_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supply_items
    ADD CONSTRAINT supply_items_pkey PRIMARY KEY (id);


--
-- Name: supply_items supply_items_supply_auto_plan_line_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supply_items
    ADD CONSTRAINT supply_items_supply_auto_plan_line_unique UNIQUE (supply_id, auto_supply_plan_line_id);


--
-- Name: supply_package_items supply_package_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supply_package_items
    ADD CONSTRAINT supply_package_items_pkey PRIMARY KEY (id);


--
-- Name: supply_packages supply_packages_barcode_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supply_packages
    ADD CONSTRAINT supply_packages_barcode_unique UNIQUE (barcode);


--
-- Name: supply_packages supply_packages_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supply_packages
    ADD CONSTRAINT supply_packages_pkey PRIMARY KEY (id);


--
-- Name: supply_plans supply_plans_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supply_plans
    ADD CONSTRAINT supply_plans_pkey PRIMARY KEY (id);


--
-- Name: supply_recommendations supply_recommendations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supply_recommendations
    ADD CONSTRAINT supply_recommendations_pkey PRIMARY KEY (id);


--
-- Name: supply_settings supply_settings_integration_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supply_settings
    ADD CONSTRAINT supply_settings_integration_id_unique UNIQUE (integration_id);


--
-- Name: supply_settings supply_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supply_settings
    ADD CONSTRAINT supply_settings_pkey PRIMARY KEY (id);


--
-- Name: sync_logs sync_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sync_logs
    ADD CONSTRAINT sync_logs_pkey PRIMARY KEY (id);


--
-- Name: timeslots_cache timeslots_cache_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.timeslots_cache
    ADD CONSTRAINT timeslots_cache_pkey PRIMARY KEY (id);


--
-- Name: unit_economics_cache ue_cache_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.unit_economics_cache
    ADD CONSTRAINT ue_cache_unique UNIQUE (integration_id, sku, fulfillment_type);


--
-- Name: unit_economics_cache unit_economics_cache_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.unit_economics_cache
    ADD CONSTRAINT unit_economics_cache_pkey PRIMARY KEY (id);


--
-- Name: unit_economics unit_economics_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.unit_economics
    ADD CONSTRAINT unit_economics_pkey PRIMARY KEY (id);


--
-- Name: unit_economics_settings unit_economics_settings_integration_id_sku_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.unit_economics_settings
    ADD CONSTRAINT unit_economics_settings_integration_id_sku_unique UNIQUE (integration_id, sku);


--
-- Name: unit_economics_settings unit_economics_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.unit_economics_settings
    ADD CONSTRAINT unit_economics_settings_pkey PRIMARY KEY (id);


--
-- Name: unit_economics unit_economics_sku_integration_scheme_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.unit_economics
    ADD CONSTRAINT unit_economics_sku_integration_scheme_unique UNIQUE (sku, integration_id, fulfillment_type);


--
-- Name: ozon_warehouse_sales uq_report_sku_wh; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ozon_warehouse_sales
    ADD CONSTRAINT uq_report_sku_wh UNIQUE (report_id, sku, warehouse_name);


--
-- Name: users users_email_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_email_unique UNIQUE (email);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: uzum_extension_snapshots uzum_ext_snapshots_payload_hash_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.uzum_extension_snapshots
    ADD CONSTRAINT uzum_ext_snapshots_payload_hash_unique UNIQUE (integration_id, payload_type, payload_hash);


--
-- Name: uzum_extension_commands uzum_extension_commands_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.uzum_extension_commands
    ADD CONSTRAINT uzum_extension_commands_pkey PRIMARY KEY (id);


--
-- Name: uzum_extension_snapshots uzum_extension_snapshots_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.uzum_extension_snapshots
    ADD CONSTRAINT uzum_extension_snapshots_pkey PRIMARY KEY (id);


--
-- Name: warehouse_slots warehouse_slots_marketplace_warehouse_id_external_slot_id_uniqu; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.warehouse_slots
    ADD CONSTRAINT warehouse_slots_marketplace_warehouse_id_external_slot_id_uniqu UNIQUE (marketplace, warehouse_id, external_slot_id);


--
-- Name: warehouse_slots warehouse_slots_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.warehouse_slots
    ADD CONSTRAINT warehouse_slots_pkey PRIMARY KEY (id);


--
-- Name: wb_barcode_costs wb_barcode_costs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wb_barcode_costs
    ADD CONSTRAINT wb_barcode_costs_pkey PRIMARY KEY (id);


--
-- Name: wildberries_spp_snapshots wb_spp_snapshot_integration_sku_uq; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wildberries_spp_snapshots
    ADD CONSTRAINT wb_spp_snapshot_integration_sku_uq UNIQUE (integration_id, sku);


--
-- Name: wildberries_tariff_snapshots wb_tariff_snapshots_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wildberries_tariff_snapshots
    ADD CONSTRAINT wb_tariff_snapshots_unique UNIQUE (integration_id, tariff_type, effective_date, warehouse_id, subject_id, scheme);


--
-- Name: wb_webhook_configs wb_webhook_configs_integration_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wb_webhook_configs
    ADD CONSTRAINT wb_webhook_configs_integration_id_key UNIQUE (integration_id);


--
-- Name: wb_webhook_configs wb_webhook_configs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wb_webhook_configs
    ADD CONSTRAINT wb_webhook_configs_pkey PRIMARY KEY (id);


--
-- Name: wildberries_spp_snapshots wildberries_spp_snapshots_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wildberries_spp_snapshots
    ADD CONSTRAINT wildberries_spp_snapshots_pkey PRIMARY KEY (id);


--
-- Name: wildberries_spp_sync_states wildberries_spp_sync_states_integration_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wildberries_spp_sync_states
    ADD CONSTRAINT wildberries_spp_sync_states_integration_id_unique UNIQUE (integration_id);


--
-- Name: wildberries_spp_sync_states wildberries_spp_sync_states_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wildberries_spp_sync_states
    ADD CONSTRAINT wildberries_spp_sync_states_pkey PRIMARY KEY (id);


--
-- Name: wildberries_tariff_snapshots wildberries_tariff_snapshots_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wildberries_tariff_snapshots
    ADD CONSTRAINT wildberries_tariff_snapshots_pkey PRIMARY KEY (id);


--
-- Name: asp_constraint_files_lookup_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX asp_constraint_files_lookup_idx ON public.auto_supply_constraint_files USING btree (integration_id, marketplace, created_at);


--
-- Name: asp_tenant_mp_account_created; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX asp_tenant_mp_account_created ON public.auto_supply_plans USING btree (tenant_id, marketplace, mp_account_id, created_at);


--
-- Name: asp_tenant_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX asp_tenant_status ON public.auto_supply_plans USING btree (tenant_id, status);


--
-- Name: aspa_plan_created_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX aspa_plan_created_index ON public.auto_supply_plan_adjustments USING btree (auto_supply_plan_id, created_at);


--
-- Name: aspl_parent_line_key_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX aspl_parent_line_key_idx ON public.auto_supply_plan_lines USING btree (parent_line_key);


--
-- Name: aspl_plan_id_risk_level_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX aspl_plan_id_risk_level_index ON public.auto_supply_plan_lines USING btree (auto_supply_plan_id, risk_level);


--
-- Name: aspl_plan_local_share_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX aspl_plan_local_share_idx ON public.auto_supply_plan_lines USING btree (auto_supply_plan_id, local_share_percent);


--
-- Name: aspl_plan_offer_dest; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX aspl_plan_offer_dest ON public.auto_supply_plan_lines USING btree (auto_supply_plan_id, offer_id, destination_id);


--
-- Name: audit_logs_auditable_type_auditable_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX audit_logs_auditable_type_auditable_id_index ON public.audit_logs USING btree (auditable_type, auditable_id);


--
-- Name: audit_logs_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX audit_logs_created_at_index ON public.audit_logs USING btree (created_at);


--
-- Name: audit_logs_event_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX audit_logs_event_index ON public.audit_logs USING btree (event);


--
-- Name: audit_logs_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX audit_logs_user_id_index ON public.audit_logs USING btree (user_id);


--
-- Name: auto_supply_constraint_files_file_hash_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX auto_supply_constraint_files_file_hash_index ON public.auto_supply_constraint_files USING btree (file_hash);


--
-- Name: auto_supply_constraint_files_integration_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX auto_supply_constraint_files_integration_id_index ON public.auto_supply_constraint_files USING btree (integration_id);


--
-- Name: auto_supply_constraint_files_marketplace_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX auto_supply_constraint_files_marketplace_index ON public.auto_supply_constraint_files USING btree (marketplace);


--
-- Name: auto_supply_plan_executions_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX auto_supply_plan_executions_status_index ON public.auto_supply_plan_executions USING btree (status);


--
-- Name: auto_supply_plan_lines_auto_supply_plan_id_cluster_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX auto_supply_plan_lines_auto_supply_plan_id_cluster_id_index ON public.auto_supply_plan_lines USING btree (auto_supply_plan_id, cluster_id);


--
-- Name: auto_supply_plan_lines_auto_supply_plan_id_sku_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX auto_supply_plan_lines_auto_supply_plan_id_sku_index ON public.auto_supply_plan_lines USING btree (auto_supply_plan_id, sku);


--
-- Name: auto_supply_plan_lines_cluster_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX auto_supply_plan_lines_cluster_id_index ON public.auto_supply_plan_lines USING btree (cluster_id);


--
-- Name: auto_supply_plan_lines_is_excluded_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX auto_supply_plan_lines_is_excluded_index ON public.auto_supply_plan_lines USING btree (is_excluded);


--
-- Name: auto_supply_plan_lines_priority_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX auto_supply_plan_lines_priority_index ON public.auto_supply_plan_lines USING btree (priority);


--
-- Name: auto_supply_plan_lines_risk_level_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX auto_supply_plan_lines_risk_level_index ON public.auto_supply_plan_lines USING btree (risk_level);


--
-- Name: auto_supply_plan_lines_sales_trend_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX auto_supply_plan_lines_sales_trend_index ON public.auto_supply_plan_lines USING btree (sales_trend);


--
-- Name: auto_supply_plan_lines_tenant_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX auto_supply_plan_lines_tenant_id_index ON public.auto_supply_plan_lines USING btree (tenant_id);


--
-- Name: auto_supply_plans_business_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX auto_supply_plans_business_status_index ON public.auto_supply_plans USING btree (business_status);


--
-- Name: auto_supply_plans_integration_id_marketplace_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX auto_supply_plans_integration_id_marketplace_index ON public.auto_supply_plans USING btree (integration_id, marketplace);


--
-- Name: auto_supply_plans_snapshot_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX auto_supply_plans_snapshot_id_index ON public.auto_supply_plans USING btree (snapshot_id);


--
-- Name: auto_supply_plans_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX auto_supply_plans_status_index ON public.auto_supply_plans USING btree (status);


--
-- Name: idx_inventory_integration_warehouse; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_inventory_integration_warehouse ON public.inventory_warehouses USING btree (integration_id, warehouse_name);


--
-- Name: idx_inventory_marketplace_integration; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_inventory_marketplace_integration ON public.inventory_warehouses USING btree (marketplace, integration_id);


--
-- Name: idx_inventory_sku_integration; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_inventory_sku_integration ON public.inventory_warehouses USING btree (sku, integration_id);


--
-- Name: idx_posting_items_sku_posting; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_posting_items_sku_posting ON public.posting_items USING btree (sku, posting_id);


--
-- Name: idx_postings_integration_in_process_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_postings_integration_in_process_at ON public.postings USING btree (integration_id, in_process_at) WHERE ((marketplace)::text = 'ozon'::text);


--
-- Name: idx_products_integration_created; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_products_integration_created ON public.products USING btree (integration_id, created_at);


--
-- Name: idx_products_integration_marketplace; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_products_integration_marketplace ON public.products USING btree (integration_id, marketplace);


--
-- Name: idx_products_integration_stock; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_products_integration_stock ON public.products USING btree (integration_id, stock);


--
-- Name: idx_ue_actual_scheme; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_ue_actual_scheme ON public.unit_economics USING btree (integration_id, marketplace, is_actual_scheme);


--
-- Name: idx_ue_integration_fulfillment; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_ue_integration_fulfillment ON public.unit_economics USING btree (integration_id, fulfillment_type);


--
-- Name: idx_ue_integration_marketplace; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_ue_integration_marketplace ON public.unit_economics USING btree (integration_id, marketplace);


--
-- Name: idx_ue_integration_sku; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_ue_integration_sku ON public.unit_economics USING btree (integration_id, sku);


--
-- Name: idx_ue_margin; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_ue_margin ON public.unit_economics USING btree (integration_id, marketplace, margin_percent);


--
-- Name: integrations_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX integrations_is_active_index ON public.integrations USING btree (is_active);


--
-- Name: integrations_marketplace_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX integrations_marketplace_index ON public.integrations USING btree (marketplace);


--
-- Name: integrations_marketplace_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX integrations_marketplace_is_active_index ON public.integrations USING btree (marketplace, is_active);


--
-- Name: integrations_work_space_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX integrations_work_space_id_index ON public.integrations USING btree (work_space_id);


--
-- Name: integrations_workspace_id_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX integrations_workspace_id_idx ON public.integrations USING btree (work_space_id, id);


--
-- Name: inventory_alerts_active_sku_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX inventory_alerts_active_sku_idx ON public.inventory_alerts USING btree (sku) WHERE (is_resolved = false);


--
-- Name: inventory_alerts_integration_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX inventory_alerts_integration_id_index ON public.inventory_alerts USING btree (integration_id);


--
-- Name: inventory_alerts_is_resolved_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX inventory_alerts_is_resolved_index ON public.inventory_alerts USING btree (is_resolved);


--
-- Name: inventory_alerts_sku_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX inventory_alerts_sku_index ON public.inventory_alerts USING btree (sku);


--
-- Name: inventory_alerts_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX inventory_alerts_type_index ON public.inventory_alerts USING btree (type);


--
-- Name: inventory_history_date_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX inventory_history_date_index ON public.inventory_history USING btree (date);


--
-- Name: inventory_history_integration_sku_date_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX inventory_history_integration_sku_date_idx ON public.inventory_history USING btree (integration_id, sku, date);


--
-- Name: inventory_history_sku_warehouse_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX inventory_history_sku_warehouse_id_index ON public.inventory_history USING btree (sku, warehouse_id);


--
-- Name: inventory_integration_market_scheme_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX inventory_integration_market_scheme_idx ON public.inventory_warehouses USING btree (integration_id, marketplace, fulfillment_type);


--
-- Name: inventory_integration_market_sku_qty_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX inventory_integration_market_sku_qty_idx ON public.inventory_warehouses USING btree (integration_id, marketplace, sku, quantity);


--
-- Name: inventory_integration_market_sku_scheme_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX inventory_integration_market_sku_scheme_idx ON public.inventory_warehouses USING btree (integration_id, marketplace, sku, fulfillment_type);


--
-- Name: inventory_warehouses_fulfillment_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX inventory_warehouses_fulfillment_type_index ON public.inventory_warehouses USING btree (fulfillment_type);


--
-- Name: inventory_warehouses_integration_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX inventory_warehouses_integration_id_index ON public.inventory_warehouses USING btree (integration_id);


--
-- Name: inventory_warehouses_marketplace_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX inventory_warehouses_marketplace_index ON public.inventory_warehouses USING btree (marketplace);


--
-- Name: inventory_warehouses_sku_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX inventory_warehouses_sku_index ON public.inventory_warehouses USING btree (sku);


--
-- Name: inventory_warehouses_sku_warehouse_id_integration_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX inventory_warehouses_sku_warehouse_id_integration_unique ON public.inventory_warehouses USING btree (sku, warehouse_id, COALESCE(integration_id, ('-1'::integer)::bigint));


--
-- Name: inventory_warehouses_stock_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX inventory_warehouses_stock_status_index ON public.inventory_warehouses USING btree (stock_status);


--
-- Name: jobs_queue_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX jobs_queue_index ON public.jobs USING btree (queue);


--
-- Name: locality_metrics_cluster_daily_date_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX locality_metrics_cluster_daily_date_idx ON public.locality_metrics_cluster_daily USING btree (integration_id, snapshot_date);


--
-- Name: locality_metrics_cluster_daily_overpay_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX locality_metrics_cluster_daily_overpay_idx ON public.locality_metrics_cluster_daily USING btree (integration_id, total_overpayment);


--
-- Name: locality_metrics_daily_date_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX locality_metrics_daily_date_idx ON public.locality_metrics_daily USING btree (integration_id, snapshot_date);


--
-- Name: locality_metrics_daily_overpay_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX locality_metrics_daily_overpay_idx ON public.locality_metrics_daily USING btree (integration_id, overpayment_amount);


--
-- Name: locality_metrics_daily_share_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX locality_metrics_daily_share_idx ON public.locality_metrics_daily USING btree (integration_id, local_share_percent);


--
-- Name: locality_recon_period_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX locality_recon_period_idx ON public.locality_reconciliation_log USING btree (integration_id, period_to);


--
-- Name: locality_recon_verdict_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX locality_recon_verdict_idx ON public.locality_reconciliation_log USING btree (integration_id, verdict);


--
-- Name: locality_recs_cluster_state_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX locality_recs_cluster_state_idx ON public.locality_recommendations USING btree (integration_id, target_cluster_id, state);


--
-- Name: locality_recs_cohort_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX locality_recs_cohort_idx ON public.locality_recommendations USING btree (cohort_id);


--
-- Name: locality_recs_rank_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX locality_recs_rank_idx ON public.locality_recommendations USING btree (integration_id, state, rank_score);


--
-- Name: locality_recs_sku_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX locality_recs_sku_idx ON public.locality_recommendations USING btree (integration_id, sku);


--
-- Name: locality_recs_supply_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX locality_recs_supply_idx ON public.locality_recommendations USING btree (linked_supply_order_id);


--
-- Name: marketplace_constraint_snapshots_integration_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX marketplace_constraint_snapshots_integration_id_index ON public.marketplace_constraint_snapshots USING btree (integration_id);


--
-- Name: marketplace_constraint_snapshots_marketplace_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX marketplace_constraint_snapshots_marketplace_index ON public.marketplace_constraint_snapshots USING btree (marketplace);


--
-- Name: ozon_ad_stats_integration_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ozon_ad_stats_integration_id_index ON public.ozon_ad_stats USING btree (integration_id);


--
-- Name: ozon_finance_txn_date_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ozon_finance_txn_date_idx ON public.ozon_finance_transactions USING btree (integration_id, operation_date);


--
-- Name: ozon_finance_txn_op_type_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ozon_finance_txn_op_type_idx ON public.ozon_finance_transactions USING btree (integration_id, operation_type);


--
-- Name: ozon_finance_txn_posting_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ozon_finance_txn_posting_idx ON public.ozon_finance_transactions USING btree (integration_id, posting_number);


--
-- Name: ozon_order_reports_integration_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ozon_order_reports_integration_id_index ON public.ozon_order_reports USING btree (integration_id);


--
-- Name: ozon_order_unit_economics_order_date_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ozon_order_unit_economics_order_date_idx ON public.ozon_order_unit_economics USING btree (integration_id, order_date);


--
-- Name: ozon_order_unit_economics_posting_item_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ozon_order_unit_economics_posting_item_idx ON public.ozon_order_unit_economics USING btree (posting_item_id);


--
-- Name: ozon_order_unit_economics_sku_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ozon_order_unit_economics_sku_idx ON public.ozon_order_unit_economics USING btree (integration_id, sku);


--
-- Name: ozon_sku_delivery_profiles_calculated_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ozon_sku_delivery_profiles_calculated_at_index ON public.ozon_sku_delivery_profiles USING btree (calculated_at);


--
-- Name: ozon_sku_delivery_profiles_dominant_demand_cluster_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ozon_sku_delivery_profiles_dominant_demand_cluster_id_index ON public.ozon_sku_delivery_profiles USING btree (dominant_demand_cluster_id);


--
-- Name: ozon_sku_delivery_profiles_dominant_sales_cluster_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ozon_sku_delivery_profiles_dominant_sales_cluster_id_index ON public.ozon_sku_delivery_profiles USING btree (dominant_sales_cluster_id);


--
-- Name: ozon_sku_delivery_profiles_dominant_stock_cluster_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ozon_sku_delivery_profiles_dominant_stock_cluster_id_index ON public.ozon_sku_delivery_profiles USING btree (dominant_stock_cluster_id);


--
-- Name: ozon_sku_delivery_profiles_integration_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ozon_sku_delivery_profiles_integration_id_index ON public.ozon_sku_delivery_profiles USING btree (integration_id);


--
-- Name: ozon_sku_delivery_profiles_offer_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ozon_sku_delivery_profiles_offer_id_index ON public.ozon_sku_delivery_profiles USING btree (offer_id);


--
-- Name: ozon_sku_delivery_profiles_ozon_sku_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ozon_sku_delivery_profiles_ozon_sku_index ON public.ozon_sku_delivery_profiles USING btree (ozon_sku);


--
-- Name: ozon_sku_delivery_profiles_scheme_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ozon_sku_delivery_profiles_scheme_index ON public.ozon_sku_delivery_profiles USING btree (scheme);


--
-- Name: ozon_sku_delivery_profiles_sku_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ozon_sku_delivery_profiles_sku_index ON public.ozon_sku_delivery_profiles USING btree (sku);


--
-- Name: ozon_supply_fixations_fixed_until_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ozon_supply_fixations_fixed_until_idx ON public.ozon_supply_fixations USING btree (integration_id, fixed_until);


--
-- Name: ozon_supply_fixations_sku_active_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ozon_supply_fixations_sku_active_idx ON public.ozon_supply_fixations USING btree (integration_id, sku, is_active);


--
-- Name: ozon_warehouse_clusters_cluster_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ozon_warehouse_clusters_cluster_id_index ON public.ozon_warehouse_clusters USING btree (cluster_id);


--
-- Name: ozon_warehouse_sales_article_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ozon_warehouse_sales_article_index ON public.ozon_warehouse_sales USING btree (article);


--
-- Name: ozon_warehouse_sales_integration_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ozon_warehouse_sales_integration_id_index ON public.ozon_warehouse_sales USING btree (integration_id);


--
-- Name: ozon_warehouse_sales_report_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ozon_warehouse_sales_report_id_index ON public.ozon_warehouse_sales USING btree (report_id);


--
-- Name: ozon_warehouse_sales_sku_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ozon_warehouse_sales_sku_index ON public.ozon_warehouse_sales USING btree (sku);


--
-- Name: ozon_warehouse_sales_warehouse_name_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ozon_warehouse_sales_warehouse_name_index ON public.ozon_warehouse_sales USING btree (warehouse_name);


--
-- Name: personal_access_tokens_expires_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX personal_access_tokens_expires_at_index ON public.personal_access_tokens USING btree (expires_at);


--
-- Name: personal_access_tokens_tokenable_type_tokenable_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX personal_access_tokens_tokenable_type_tokenable_id_index ON public.personal_access_tokens USING btree (tokenable_type, tokenable_id);


--
-- Name: plan_line_eval_lookup_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX plan_line_eval_lookup_idx ON public.plan_line_evaluations USING btree (integration_id, sku, evaluated_at);


--
-- Name: plan_line_evaluations_auto_supply_plan_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX plan_line_evaluations_auto_supply_plan_id_index ON public.plan_line_evaluations USING btree (auto_supply_plan_id);


--
-- Name: plan_line_evaluations_auto_supply_plan_line_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX plan_line_evaluations_auto_supply_plan_line_id_index ON public.plan_line_evaluations USING btree (auto_supply_plan_line_id);


--
-- Name: plan_line_evaluations_integration_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX plan_line_evaluations_integration_id_index ON public.plan_line_evaluations USING btree (integration_id);


--
-- Name: plan_line_evaluations_sku_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX plan_line_evaluations_sku_index ON public.plan_line_evaluations USING btree (sku);


--
-- Name: planning_fact_snapshots_auto_supply_plan_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX planning_fact_snapshots_auto_supply_plan_id_index ON public.planning_fact_snapshots USING btree (auto_supply_plan_id);


--
-- Name: planning_fact_snapshots_integration_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX planning_fact_snapshots_integration_id_index ON public.planning_fact_snapshots USING btree (integration_id);


--
-- Name: planning_fact_snapshots_marketplace_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX planning_fact_snapshots_marketplace_index ON public.planning_fact_snapshots USING btree (marketplace);


--
-- Name: planning_fact_snapshots_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX planning_fact_snapshots_status_index ON public.planning_fact_snapshots USING btree (status);


--
-- Name: posting_items_posting_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX posting_items_posting_id_index ON public.posting_items USING btree (posting_id);


--
-- Name: posting_items_posting_id_sku_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX posting_items_posting_id_sku_index ON public.posting_items USING btree (posting_id, sku);


--
-- Name: posting_items_sku_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX posting_items_sku_index ON public.posting_items USING btree (sku);


--
-- Name: postings_integration_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX postings_integration_id_index ON public.postings USING btree (integration_id);


--
-- Name: postings_integration_id_shipment_date_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX postings_integration_id_shipment_date_index ON public.postings USING btree (integration_id, shipment_date);


--
-- Name: postings_integration_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX postings_integration_id_status_index ON public.postings USING btree (integration_id, status);


--
-- Name: postings_marketplace_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX postings_marketplace_index ON public.postings USING btree (marketplace);


--
-- Name: postings_marketplace_posting_number_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX postings_marketplace_posting_number_index ON public.postings USING btree (marketplace, posting_number);


--
-- Name: postings_posting_number_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX postings_posting_number_index ON public.postings USING btree (posting_number);


--
-- Name: postings_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX postings_status_index ON public.postings USING btree (status);


--
-- Name: products_brand_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX products_brand_index ON public.products USING btree (brand);


--
-- Name: products_category_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX products_category_index ON public.products USING btree (category);


--
-- Name: products_integration_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX products_integration_id_index ON public.products USING btree (integration_id);


--
-- Name: products_integration_market_barcode_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX products_integration_market_barcode_idx ON public.products USING btree (integration_id, marketplace, barcode);


--
-- Name: products_integration_market_scheme_sku_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX products_integration_market_scheme_sku_idx ON public.products USING btree (integration_id, marketplace, fulfillment_type, sku);


--
-- Name: products_integration_market_sku_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX products_integration_market_sku_idx ON public.products USING btree (integration_id, marketplace, sku);


--
-- Name: products_integration_market_stock_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX products_integration_market_stock_idx ON public.products USING btree (integration_id, marketplace, stock);


--
-- Name: products_integration_market_vendor_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX products_integration_market_vendor_idx ON public.products USING btree (integration_id, marketplace, vendor_code);


--
-- Name: products_marketplace_fulfillment_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX products_marketplace_fulfillment_type_index ON public.products USING btree (marketplace, fulfillment_type);


--
-- Name: products_marketplace_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX products_marketplace_index ON public.products USING btree (marketplace);


--
-- Name: products_marketplace_integration_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX products_marketplace_integration_index ON public.products USING btree (marketplace, marketplace_id, integration_id);


--
-- Name: products_sku_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX products_sku_index ON public.products USING btree (sku);


--
-- Name: seller_warehouse_stocks_integration_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX seller_warehouse_stocks_integration_id_index ON public.seller_warehouse_stocks USING btree (integration_id);


--
-- Name: seller_warehouse_stocks_sku_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX seller_warehouse_stocks_sku_index ON public.seller_warehouse_stocks USING btree (sku);


--
-- Name: sessions_last_activity_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sessions_last_activity_index ON public.sessions USING btree (last_activity);


--
-- Name: sessions_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sessions_user_id_index ON public.sessions USING btree (user_id);


--
-- Name: shipment_items_shipment_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX shipment_items_shipment_id_index ON public.shipment_items USING btree (shipment_id);


--
-- Name: shipment_items_sku_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX shipment_items_sku_index ON public.shipment_items USING btree (sku);


--
-- Name: shipment_recommendations_integration_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX shipment_recommendations_integration_id_index ON public.shipment_recommendations USING btree (integration_id);


--
-- Name: shipment_recommendations_is_used_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX shipment_recommendations_is_used_index ON public.shipment_recommendations USING btree (is_used);


--
-- Name: shipment_recommendations_priority_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX shipment_recommendations_priority_index ON public.shipment_recommendations USING btree (priority);


--
-- Name: shipments_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX shipments_created_at_index ON public.shipments USING btree (created_at);


--
-- Name: shipments_external_supply_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX shipments_external_supply_id_index ON public.shipments USING btree (external_supply_id);


--
-- Name: shipments_integration_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX shipments_integration_id_index ON public.shipments USING btree (integration_id);


--
-- Name: shipments_macrolocal_cluster_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX shipments_macrolocal_cluster_id_index ON public.shipments USING btree (macrolocal_cluster_id);


--
-- Name: shipments_marketplace_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX shipments_marketplace_index ON public.shipments USING btree (marketplace);


--
-- Name: shipments_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX shipments_status_index ON public.shipments USING btree (status);


--
-- Name: shipments_supplier_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX shipments_supplier_id_index ON public.shipments USING btree (supplier_id);


--
-- Name: shipments_supply_plan_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX shipments_supply_plan_id_index ON public.shipments USING btree (supply_plan_id);


--
-- Name: shipments_workspace_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX shipments_workspace_id_index ON public.shipments USING btree (workspace_id);


--
-- Name: suppliers_name_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX suppliers_name_index ON public.suppliers USING btree (name);


--
-- Name: suppliers_workspace_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX suppliers_workspace_id_index ON public.suppliers USING btree (workspace_id);


--
-- Name: supplies_cluster_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX supplies_cluster_id_index ON public.supplies USING btree (cluster_id);


--
-- Name: supplies_integration_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX supplies_integration_id_created_at_index ON public.supplies USING btree (integration_id, created_at);


--
-- Name: supplies_integration_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX supplies_integration_id_status_index ON public.supplies USING btree (integration_id, status);


--
-- Name: supplies_ozon_draft_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX supplies_ozon_draft_id_index ON public.supplies USING btree (ozon_draft_id);


--
-- Name: supplies_ozon_order_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX supplies_ozon_order_id_index ON public.supplies USING btree (ozon_order_id);


--
-- Name: supplies_ozon_supply_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX supplies_ozon_supply_id_index ON public.supplies USING btree (ozon_supply_id);


--
-- Name: supplies_planned_delivery_date_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX supplies_planned_delivery_date_index ON public.supplies USING btree (planned_delivery_date);


--
-- Name: supplies_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX supplies_status_index ON public.supplies USING btree (status);


--
-- Name: supplies_warehouse_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX supplies_warehouse_id_index ON public.supplies USING btree (warehouse_id);


--
-- Name: supply_analytics_cluster_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX supply_analytics_cluster_id_index ON public.supply_analytics USING btree (cluster_id);


--
-- Name: supply_analytics_date_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX supply_analytics_date_index ON public.supply_analytics USING btree (date);


--
-- Name: supply_analytics_integration_id_date_period_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX supply_analytics_integration_id_date_period_type_index ON public.supply_analytics USING btree (integration_id, date, period_type);


--
-- Name: supply_analytics_sku_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX supply_analytics_sku_index ON public.supply_analytics USING btree (sku);


--
-- Name: supply_analytics_warehouse_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX supply_analytics_warehouse_id_index ON public.supply_analytics USING btree (warehouse_id);


--
-- Name: supply_documents_package_id_document_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX supply_documents_package_id_document_type_index ON public.supply_documents USING btree (package_id, document_type);


--
-- Name: supply_documents_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX supply_documents_status_index ON public.supply_documents USING btree (status);


--
-- Name: supply_documents_supply_id_document_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX supply_documents_supply_id_document_type_index ON public.supply_documents USING btree (supply_id, document_type);


--
-- Name: supply_events_event_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX supply_events_event_type_index ON public.supply_events USING btree (event_type);


--
-- Name: supply_events_supply_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX supply_events_supply_id_created_at_index ON public.supply_events USING btree (supply_id, created_at);


--
-- Name: supply_events_supply_id_event_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX supply_events_supply_id_event_type_index ON public.supply_events USING btree (supply_id, event_type);


--
-- Name: supply_items_sku_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX supply_items_sku_index ON public.supply_items USING btree (sku);


--
-- Name: supply_items_supply_id_sku_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX supply_items_supply_id_sku_index ON public.supply_items USING btree (supply_id, sku);


--
-- Name: supply_items_supply_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX supply_items_supply_id_status_index ON public.supply_items USING btree (supply_id, status);


--
-- Name: supply_package_items_package_id_sku_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX supply_package_items_package_id_sku_index ON public.supply_package_items USING btree (package_id, sku);


--
-- Name: supply_package_items_supply_item_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX supply_package_items_supply_item_id_index ON public.supply_package_items USING btree (supply_item_id);


--
-- Name: supply_packages_barcode_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX supply_packages_barcode_index ON public.supply_packages USING btree (barcode);


--
-- Name: supply_packages_supply_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX supply_packages_supply_id_status_index ON public.supply_packages USING btree (supply_id, status);


--
-- Name: supply_plans_integration_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX supply_plans_integration_id_index ON public.supply_plans USING btree (integration_id);


--
-- Name: supply_plans_macrolocal_cluster_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX supply_plans_macrolocal_cluster_id_index ON public.supply_plans USING btree (macrolocal_cluster_id);


--
-- Name: supply_plans_marketplace_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX supply_plans_marketplace_status_index ON public.supply_plans USING btree (marketplace, status);


--
-- Name: supply_plans_period_start_period_end_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX supply_plans_period_start_period_end_index ON public.supply_plans USING btree (period_start, period_end);


--
-- Name: supply_recommendations_cluster_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX supply_recommendations_cluster_id_index ON public.supply_recommendations USING btree (cluster_id);


--
-- Name: supply_recommendations_integration_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX supply_recommendations_integration_id_index ON public.supply_recommendations USING btree (integration_id);


--
-- Name: supply_recommendations_is_used_is_dismissed_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX supply_recommendations_is_used_is_dismissed_index ON public.supply_recommendations USING btree (is_used, is_dismissed);


--
-- Name: supply_recommendations_marketplace_priority_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX supply_recommendations_marketplace_priority_index ON public.supply_recommendations USING btree (marketplace, priority);


--
-- Name: supply_recommendations_state_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX supply_recommendations_state_index ON public.supply_recommendations USING btree (state);


--
-- Name: supply_recommendations_supply_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX supply_recommendations_supply_id_index ON public.supply_recommendations USING btree (supply_id);


--
-- Name: sync_logs_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sync_logs_created_at_index ON public.sync_logs USING btree (created_at);


--
-- Name: sync_logs_marketplace_sync_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sync_logs_marketplace_sync_type_index ON public.sync_logs USING btree (marketplace, sync_type);


--
-- Name: sync_logs_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sync_logs_status_index ON public.sync_logs USING btree (status);


--
-- Name: timeslots_cache_cluster_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX timeslots_cache_cluster_id_index ON public.timeslots_cache USING btree (cluster_id);


--
-- Name: timeslots_cache_draft_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX timeslots_cache_draft_id_index ON public.timeslots_cache USING btree (draft_id);


--
-- Name: timeslots_cache_expires_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX timeslots_cache_expires_at_index ON public.timeslots_cache USING btree (expires_at);


--
-- Name: timeslots_cache_integration_id_warehouse_id_slot_date_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX timeslots_cache_integration_id_warehouse_id_slot_date_index ON public.timeslots_cache USING btree (integration_id, warehouse_id, slot_date);


--
-- Name: timeslots_cache_slot_date_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX timeslots_cache_slot_date_index ON public.timeslots_cache USING btree (slot_date);


--
-- Name: timeslots_cache_timeslot_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX timeslots_cache_timeslot_id_index ON public.timeslots_cache USING btree (timeslot_id);


--
-- Name: timeslots_cache_warehouse_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX timeslots_cache_warehouse_id_index ON public.timeslots_cache USING btree (warehouse_id);


--
-- Name: ue_cache_filter_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ue_cache_filter_idx ON public.unit_economics_cache USING btree (integration_id, marketplace, fulfillment_type, net_profit);


--
-- Name: ue_cache_margin_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ue_cache_margin_idx ON public.unit_economics_cache USING btree (integration_id, marketplace, fulfillment_type, margin_percent);


--
-- Name: ue_cache_market_scheme_price_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ue_cache_market_scheme_price_idx ON public.unit_economics_cache USING btree (integration_id, marketplace, fulfillment_type, price);


--
-- Name: ue_cache_market_scheme_sku_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ue_cache_market_scheme_sku_idx ON public.unit_economics_cache USING btree (integration_id, marketplace, fulfillment_type, sku);


--
-- Name: ue_cache_market_scheme_updated_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ue_cache_market_scheme_updated_idx ON public.unit_economics_cache USING btree (integration_id, marketplace, fulfillment_type, updated_at);


--
-- Name: ue_cache_market_sku_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ue_cache_market_sku_idx ON public.unit_economics_cache USING btree (integration_id, marketplace, sku);


--
-- Name: ue_cache_sku_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ue_cache_sku_idx ON public.unit_economics_cache USING btree (sku);


--
-- Name: ue_settings_integration_sku_cost_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ue_settings_integration_sku_cost_idx ON public.unit_economics_settings USING btree (integration_id, sku, cost_price);


--
-- Name: unit_economics_cache_integration_id_fulfillment_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX unit_economics_cache_integration_id_fulfillment_type_index ON public.unit_economics_cache USING btree (integration_id, fulfillment_type);


--
-- Name: unit_economics_cache_integration_id_marketplace_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX unit_economics_cache_integration_id_marketplace_index ON public.unit_economics_cache USING btree (integration_id, marketplace);


--
-- Name: unit_economics_cache_product_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX unit_economics_cache_product_id_index ON public.unit_economics_cache USING btree (product_id);


--
-- Name: unit_economics_integration_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX unit_economics_integration_id_index ON public.unit_economics USING btree (integration_id);


--
-- Name: unit_economics_marketplace_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX unit_economics_marketplace_index ON public.unit_economics USING btree (marketplace);


--
-- Name: unit_economics_period_start_period_end_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX unit_economics_period_start_period_end_index ON public.unit_economics USING btree (period_start, period_end);


--
-- Name: unit_economics_scheme_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX unit_economics_scheme_idx ON public.unit_economics USING btree (integration_id, sku, fulfillment_type);


--
-- Name: unit_economics_settings_integration_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX unit_economics_settings_integration_id_index ON public.unit_economics_settings USING btree (integration_id);


--
-- Name: unit_economics_sku_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX unit_economics_sku_index ON public.unit_economics USING btree (sku);


--
-- Name: uzum_ext_cmd_pending_lookup; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX uzum_ext_cmd_pending_lookup ON public.uzum_extension_commands USING btree (integration_id, status, expires_at);


--
-- Name: uzum_ext_snap_lookup; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX uzum_ext_snap_lookup ON public.uzum_extension_snapshots USING btree (integration_id, payload_type, collected_at);


--
-- Name: uzum_extension_commands_command_key_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX uzum_extension_commands_command_key_index ON public.uzum_extension_commands USING btree (command_key);


--
-- Name: uzum_extension_commands_expires_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX uzum_extension_commands_expires_at_index ON public.uzum_extension_commands USING btree (expires_at);


--
-- Name: uzum_extension_commands_integration_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX uzum_extension_commands_integration_id_index ON public.uzum_extension_commands USING btree (integration_id);


--
-- Name: uzum_extension_commands_integration_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX uzum_extension_commands_integration_id_status_index ON public.uzum_extension_commands USING btree (integration_id, status);


--
-- Name: uzum_extension_snapshots_integration_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX uzum_extension_snapshots_integration_id_index ON public.uzum_extension_snapshots USING btree (integration_id);


--
-- Name: uzum_extension_snapshots_payload_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX uzum_extension_snapshots_payload_type_index ON public.uzum_extension_snapshots USING btree (payload_type);


--
-- Name: warehouse_slots_booked_by_supply_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX warehouse_slots_booked_by_supply_id_index ON public.warehouse_slots USING btree (booked_by_supply_id);


--
-- Name: warehouse_slots_is_available_date_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX warehouse_slots_is_available_date_index ON public.warehouse_slots USING btree (is_available, date);


--
-- Name: warehouse_slots_macrolocal_cluster_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX warehouse_slots_macrolocal_cluster_id_index ON public.warehouse_slots USING btree (macrolocal_cluster_id);


--
-- Name: warehouse_slots_marketplace_warehouse_id_date_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX warehouse_slots_marketplace_warehouse_id_date_index ON public.warehouse_slots USING btree (marketplace, warehouse_id, date);


--
-- Name: wb_barcode_costs_integration_barcode_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX wb_barcode_costs_integration_barcode_unique ON public.wb_barcode_costs USING btree (integration_id, barcode);


--
-- Name: wb_spp_snapshot_integration_nm_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX wb_spp_snapshot_integration_nm_idx ON public.wildberries_spp_snapshots USING btree (integration_id, nm_id);


--
-- Name: wb_tariff_snapshots_lookup_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX wb_tariff_snapshots_lookup_idx ON public.wildberries_tariff_snapshots USING btree (integration_id, tariff_type, effective_date);


--
-- Name: wb_tariff_snapshots_subject_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX wb_tariff_snapshots_subject_idx ON public.wildberries_tariff_snapshots USING btree (integration_id, subject_id);


--
-- Name: wb_tariff_snapshots_warehouse_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX wb_tariff_snapshots_warehouse_idx ON public.wildberries_tariff_snapshots USING btree (integration_id, warehouse_name);


--
-- Name: wildberries_spp_sync_states_retry_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX wildberries_spp_sync_states_retry_at_index ON public.wildberries_spp_sync_states USING btree (retry_at);


--
-- Name: wildberries_tariff_snapshots_integration_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX wildberries_tariff_snapshots_integration_id_index ON public.wildberries_tariff_snapshots USING btree (integration_id);


--
-- Name: auto_supply_plan_adjustments auto_supply_plan_adjustments_auto_supply_plan_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auto_supply_plan_adjustments
    ADD CONSTRAINT auto_supply_plan_adjustments_auto_supply_plan_id_foreign FOREIGN KEY (auto_supply_plan_id) REFERENCES public.auto_supply_plans(id) ON DELETE CASCADE;


--
-- Name: auto_supply_plan_adjustments auto_supply_plan_adjustments_auto_supply_plan_line_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auto_supply_plan_adjustments
    ADD CONSTRAINT auto_supply_plan_adjustments_auto_supply_plan_line_id_foreign FOREIGN KEY (auto_supply_plan_line_id) REFERENCES public.auto_supply_plan_lines(id) ON DELETE SET NULL;


--
-- Name: auto_supply_plan_executions auto_supply_plan_executions_auto_supply_plan_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auto_supply_plan_executions
    ADD CONSTRAINT auto_supply_plan_executions_auto_supply_plan_id_foreign FOREIGN KEY (auto_supply_plan_id) REFERENCES public.auto_supply_plans(id) ON DELETE CASCADE;


--
-- Name: auto_supply_plan_executions auto_supply_plan_executions_integration_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auto_supply_plan_executions
    ADD CONSTRAINT auto_supply_plan_executions_integration_id_foreign FOREIGN KEY (integration_id) REFERENCES public.integrations(id) ON DELETE CASCADE;


--
-- Name: auto_supply_plan_lines auto_supply_plan_lines_auto_supply_plan_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auto_supply_plan_lines
    ADD CONSTRAINT auto_supply_plan_lines_auto_supply_plan_id_foreign FOREIGN KEY (auto_supply_plan_id) REFERENCES public.auto_supply_plans(id) ON DELETE CASCADE;


--
-- Name: ozon_warehouse_sales ozon_warehouse_sales_report_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ozon_warehouse_sales
    ADD CONSTRAINT ozon_warehouse_sales_report_id_foreign FOREIGN KEY (report_id) REFERENCES public.ozon_order_reports(id) ON DELETE CASCADE;


--
-- Name: posting_items posting_items_posting_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.posting_items
    ADD CONSTRAINT posting_items_posting_id_foreign FOREIGN KEY (posting_id) REFERENCES public.postings(id) ON DELETE CASCADE;


--
-- Name: shipment_items shipment_items_shipment_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.shipment_items
    ADD CONSTRAINT shipment_items_shipment_id_foreign FOREIGN KEY (shipment_id) REFERENCES public.shipments(id) ON DELETE CASCADE;


--
-- Name: shipments shipments_supplier_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.shipments
    ADD CONSTRAINT shipments_supplier_id_foreign FOREIGN KEY (supplier_id) REFERENCES public.suppliers(id) ON DELETE CASCADE;


--
-- Name: supplies supplies_aspe_fk; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supplies
    ADD CONSTRAINT supplies_aspe_fk FOREIGN KEY (auto_supply_plan_execution_id) REFERENCES public.auto_supply_plan_executions(id) ON DELETE SET NULL;


--
-- Name: supplies supplies_auto_supply_plan_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supplies
    ADD CONSTRAINT supplies_auto_supply_plan_id_foreign FOREIGN KEY (auto_supply_plan_id) REFERENCES public.auto_supply_plans(id) ON DELETE SET NULL;


--
-- Name: supplies supplies_integration_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supplies
    ADD CONSTRAINT supplies_integration_id_foreign FOREIGN KEY (integration_id) REFERENCES public.integrations(id) ON DELETE CASCADE;


--
-- Name: supply_analytics supply_analytics_integration_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supply_analytics
    ADD CONSTRAINT supply_analytics_integration_id_foreign FOREIGN KEY (integration_id) REFERENCES public.integrations(id) ON DELETE CASCADE;


--
-- Name: supply_documents supply_documents_generated_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supply_documents
    ADD CONSTRAINT supply_documents_generated_by_foreign FOREIGN KEY (generated_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: supply_documents supply_documents_package_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supply_documents
    ADD CONSTRAINT supply_documents_package_id_foreign FOREIGN KEY (package_id) REFERENCES public.supply_packages(id) ON DELETE SET NULL;


--
-- Name: supply_documents supply_documents_supply_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supply_documents
    ADD CONSTRAINT supply_documents_supply_id_foreign FOREIGN KEY (supply_id) REFERENCES public.supplies(id) ON DELETE CASCADE;


--
-- Name: supply_events supply_events_supply_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supply_events
    ADD CONSTRAINT supply_events_supply_id_foreign FOREIGN KEY (supply_id) REFERENCES public.supplies(id) ON DELETE CASCADE;


--
-- Name: supply_events supply_events_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supply_events
    ADD CONSTRAINT supply_events_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: supply_items supply_items_auto_supply_plan_line_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supply_items
    ADD CONSTRAINT supply_items_auto_supply_plan_line_id_foreign FOREIGN KEY (auto_supply_plan_line_id) REFERENCES public.auto_supply_plan_lines(id) ON DELETE SET NULL;


--
-- Name: supply_items supply_items_supply_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supply_items
    ADD CONSTRAINT supply_items_supply_id_foreign FOREIGN KEY (supply_id) REFERENCES public.supplies(id) ON DELETE CASCADE;


--
-- Name: supply_package_items supply_package_items_package_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supply_package_items
    ADD CONSTRAINT supply_package_items_package_id_foreign FOREIGN KEY (package_id) REFERENCES public.supply_packages(id) ON DELETE CASCADE;


--
-- Name: supply_package_items supply_package_items_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supply_package_items
    ADD CONSTRAINT supply_package_items_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE SET NULL;


--
-- Name: supply_package_items supply_package_items_scanned_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supply_package_items
    ADD CONSTRAINT supply_package_items_scanned_by_foreign FOREIGN KEY (scanned_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: supply_package_items supply_package_items_supply_item_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supply_package_items
    ADD CONSTRAINT supply_package_items_supply_item_id_foreign FOREIGN KEY (supply_item_id) REFERENCES public.supply_items(id) ON DELETE SET NULL;


--
-- Name: supply_packages supply_packages_packed_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supply_packages
    ADD CONSTRAINT supply_packages_packed_by_foreign FOREIGN KEY (packed_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: supply_packages supply_packages_supply_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supply_packages
    ADD CONSTRAINT supply_packages_supply_id_foreign FOREIGN KEY (supply_id) REFERENCES public.supplies(id) ON DELETE CASCADE;


--
-- Name: supply_settings supply_settings_integration_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supply_settings
    ADD CONSTRAINT supply_settings_integration_id_foreign FOREIGN KEY (integration_id) REFERENCES public.integrations(id) ON DELETE CASCADE;


--
-- Name: timeslots_cache timeslots_cache_integration_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.timeslots_cache
    ADD CONSTRAINT timeslots_cache_integration_id_foreign FOREIGN KEY (integration_id) REFERENCES public.integrations(id) ON DELETE CASCADE;


--
-- Name: unit_economics_cache unit_economics_cache_integration_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.unit_economics_cache
    ADD CONSTRAINT unit_economics_cache_integration_id_foreign FOREIGN KEY (integration_id) REFERENCES public.integrations(id) ON DELETE CASCADE;


--
-- Name: unit_economics_cache unit_economics_cache_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.unit_economics_cache
    ADD CONSTRAINT unit_economics_cache_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE CASCADE;


--
-- Name: unit_economics_settings unit_economics_settings_integration_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.unit_economics_settings
    ADD CONSTRAINT unit_economics_settings_integration_id_foreign FOREIGN KEY (integration_id) REFERENCES public.integrations(id) ON DELETE CASCADE;


--
-- Name: wildberries_spp_snapshots wildberries_spp_snapshots_integration_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wildberries_spp_snapshots
    ADD CONSTRAINT wildberries_spp_snapshots_integration_id_foreign FOREIGN KEY (integration_id) REFERENCES public.integrations(id) ON DELETE CASCADE;


--
-- Name: wildberries_spp_sync_states wildberries_spp_sync_states_integration_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wildberries_spp_sync_states
    ADD CONSTRAINT wildberries_spp_sync_states_integration_id_foreign FOREIGN KEY (integration_id) REFERENCES public.integrations(id) ON DELETE CASCADE;


--
-- PostgreSQL database dump complete
--


