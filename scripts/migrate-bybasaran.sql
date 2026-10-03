-- Veriyi bybasaran_old (eski sema, tekil tablo) -> bybasaran (yeni sema)

SET FOREIGN_KEY_CHECKS = 0;
SET UNIQUE_CHECKS = 0;

USE bybasaran;

-- Hedef tablolari temizle (yeni sema)
TRUNCATE TABLE task_logs;
TRUNCATE TABLE tasks;
TRUNCATE TABLE sessions;
TRUNCATE TABLE notifications;
TRUNCATE TABLE messages;
TRUNCATE TABLE message_templates;
TRUNCATE TABLE documents;
TRUNCATE TABLE policies;
TRUNCATE TABLE customers;
TRUNCATE TABLE customer_categories;
TRUNCATE TABLE insurance_types;
TRUNCATE TABLE companies;
TRUNCATE TABLE districts;
TRUNCATE TABLE cities;
TRUNCATE TABLE countries;
TRUNCATE TABLE branches;
TRUNCATE TABLE users;
TRUNCATE TABLE settings;
TRUNCATE TABLE audit_logs;

-- country -> countries
INSERT INTO countries (id, name, created_at, updated_at, deleted_at)
SELECT id, name, created_at, COALESCE(updated_at, created_at), deleted_at FROM bybasaran_old.country;

-- city -> cities (eski: country / yeni: country_id)
INSERT INTO cities (id, country_id, name, created_at, updated_at)
SELECT id, country, name, created_at, COALESCE(updated_at, created_at) FROM bybasaran_old.city;

-- district -> districts (eski: city / yeni: city_id)
INSERT INTO districts (id, city_id, name, created_at, updated_at)
SELECT id, city, name, created_at, COALESCE(updated_at, created_at) FROM bybasaran_old.district;

-- branch -> branches (eski: phone_number, commission / yeni: phone, commission_rate)
INSERT INTO branches (id, name, phone, commission_rate, iban, created_at, updated_at, deleted_at)
SELECT id, name, phone_number, COALESCE(commission, 0), iban, created_at, COALESCE(updated_at, created_at), deleted_at FROM bybasaran_old.branch;

-- users (eski: full_name, status, type, branch / yeni: name, is_active, role, branch_id)
INSERT INTO users (id, name, email, password, is_active, role, branch_id, created_at, updated_at, deleted_at)
SELECT
    id,
    full_name,
    email,
    password,
    CAST(status AS UNSIGNED),
    CAST(type AS UNSIGNED),
    branch,
    created_at,
    COALESCE(updated_at, created_at),
    deleted_at
FROM bybasaran_old.users;

-- company -> companies
INSERT INTO companies (id, name, color, created_at, updated_at, deleted_at)
SELECT id, name, color, created_at, COALESCE(updated_at, created_at), deleted_at FROM bybasaran_old.company;

-- insurance -> insurance_types (eski: key->code, commission->default_comm_rate, commission2->extra_comm_rate, type->level, parent->parent_id, status->is_active, chart_status->show_in_charts, group->branch_group, allianz_id->external_code)
INSERT INTO insurance_types (id, name, code, color, default_comm_rate, extra_comm_rate, level, parent_id, is_active, show_in_charts, branch_group, external_code, renewal_days, created_at, updated_at, deleted_at)
SELECT
    id,
    name,
    `key`,
    color,
    COALESCE(commission, 0),
    COALESCE(commission2, 0),
    type,
    parent,
    CAST(status AS UNSIGNED),
    CAST(chart_status AS UNSIGNED),
    `group`,
    allianz_id,
    15,                                  -- renewal_days varsayilan
    created_at,
    COALESCE(updated_at, created_at),
    deleted_at
FROM bybasaran_old.insurance;

-- customers (eski: identity_number, primary_phone, secondary_phone, authorized_full_name, martial_status, number_of_children_employees, company_name_or_sector, country, city, district
--           yeni: identity_no, phone, phone_alt, contact_person, marital_status, dependents_count, sector, country_id, city_id, district_id, customer_type)
INSERT INTO customers (id, customer_type, name, identity_no, tax_office, birth_date, phone, email, contact_person, phone_alt, marital_status, job, dependents_count, sector, country_id, city_id, district_id, address, note, created_at, updated_at, deleted_at)
SELECT
    id,
    type,
    name,
    identity_number,
    tax_office,
    -- birth_date varchar (DD-MM-YYYY ya da YYYY-MM-DD olabilir) -> DATE
    CASE
      WHEN birth_date REGEXP '^[0-9]{2}-[0-9]{2}-[0-9]{4}$' THEN STR_TO_DATE(birth_date, '%d-%m-%Y')
      WHEN birth_date REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}' THEN STR_TO_DATE(birth_date, '%Y-%m-%d')
      WHEN birth_date REGEXP '^[0-9]{2}/[0-9]{2}/[0-9]{4}$' THEN STR_TO_DATE(birth_date, '%d/%m/%Y')
      ELSE NULL
    END,
    primary_phone,
    email,
    authorized_full_name,
    secondary_phone,
    martial_status,
    job,
    number_of_children_employees,
    company_name_or_sector,
    country,
    city,
    district,
    address,
    note,
    created_at,
    COALESCE(updated_at, created_at),
    deleted_at
FROM bybasaran_old.customers;

-- policy -> policies (cok sayida alan eslemesi)
INSERT INTO policies (id, production_type, customer_id, insurance_type_id, company_id, branch_id, policy_no, insured_name, issued_at, starts_at, expires_at, gross_premium, net_premium, company_comm_rate, branch_comm_rate, is_approved, is_cancelled, endorsement_no, uavt_code, additional_insureds, network, dask_no, chassis_no, engine_no, registration_no, plate_no, vehicle_year, vehicle_brand, vehicle_model, reference_source, created_at, updated_at, deleted_at)
SELECT
    id,
    CASE prod WHEN 'FROM_OUT' THEN 'INCOMING' WHEN 'TO_OUT' THEN 'OUTGOING' ELSE 'SELF' END,
    customer,
    insurance,
    company,
    branch,
    policy_number,
    insured,
    -- tarih alanlari varchar (DD/MM/YYYY ya da YYYY-MM-DD) -> DATE
    CASE
      WHEN issue_date REGEXP '^[0-9]{2}/[0-9]{2}/[0-9]{4}$' THEN STR_TO_DATE(issue_date, '%d/%m/%Y')
      WHEN issue_date REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}' THEN STR_TO_DATE(issue_date, '%Y-%m-%d')
      WHEN issue_date REGEXP '^[0-9]{2}-[0-9]{2}-[0-9]{4}$' THEN STR_TO_DATE(issue_date, '%d-%m-%Y')
      ELSE NULL
    END,
    CASE
      WHEN start_date REGEXP '^[0-9]{2}/[0-9]{2}/[0-9]{4}$' THEN STR_TO_DATE(start_date, '%d/%m/%Y')
      WHEN start_date REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}' THEN STR_TO_DATE(start_date, '%Y-%m-%d')
      WHEN start_date REGEXP '^[0-9]{2}-[0-9]{2}-[0-9]{4}$' THEN STR_TO_DATE(start_date, '%d-%m-%Y')
      ELSE NULL
    END,
    CASE
      WHEN finish_date REGEXP '^[0-9]{2}/[0-9]{2}/[0-9]{4}$' THEN STR_TO_DATE(finish_date, '%d/%m/%Y')
      WHEN finish_date REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}' THEN STR_TO_DATE(finish_date, '%Y-%m-%d')
      WHEN finish_date REGEXP '^[0-9]{2}-[0-9]{2}-[0-9]{4}$' THEN STR_TO_DATE(finish_date, '%d-%m-%Y')
      ELSE NULL
    END,
    -- prim alanlari varchar -> DECIMAL (her tur garip karakteri temizle)
    IFNULL(NULLIF(REPLACE(REPLACE(REPLACE(IFNULL(gross_amount, ''), ',', '.'), ' ', ''), 'TL', ''), '') + 0, 0),
    IFNULL(NULLIF(REPLACE(REPLACE(REPLACE(IFNULL(net_amount, ''), ',', '.'), ' ', ''), 'TL', ''), '') + 0, 0),
    COALESCE(company_commission, 0),
    COALESCE(branch_commission, 0),
    CAST(IFNULL(consensus, '0') AS UNSIGNED),
    CAST(IFNULL(is_cancel, '0') AS UNSIGNED),
    COALESCE(zeyil_number, 1),
    uavt,
    insureds,
    network,
    dask_policy_number,
    chassis_number,
    engine_number,
    license_serial_number,
    plate,
    model_year,
    brand,
    model,
    -- ref varchar(5) -> insurance_types id'sine cevirmek istemeyiz; NULL birak
    NULL,
    created_at,
    COALESCE(updated_at, created_at),
    deleted_at
FROM bybasaran_old.policy;

SET FOREIGN_KEY_CHECKS = 1;
SET UNIQUE_CHECKS = 1;

-- Kontrol
SELECT 'countries' tbl, COUNT(*) cnt FROM countries
UNION SELECT 'cities', COUNT(*) FROM cities
UNION SELECT 'districts', COUNT(*) FROM districts
UNION SELECT 'branches', COUNT(*) FROM branches
UNION SELECT 'users', COUNT(*) FROM users
UNION SELECT 'companies', COUNT(*) FROM companies
UNION SELECT 'insurance_types', COUNT(*) FROM insurance_types
UNION SELECT 'customers', COUNT(*) FROM customers
UNION SELECT 'policies', COUNT(*) FROM policies;
