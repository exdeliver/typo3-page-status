-- Table for storing page status information
CREATE TABLE tx_pagestatus_domain_model_pagestatus (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT 0 NOT NULL,
    page_id int(11) DEFAULT 0 NOT NULL,
    page_url varchar(2048) DEFAULT '' NOT NULL,
    is_online tinyint(1) DEFAULT 1 NOT NULL,
    http_status_code int(11) DEFAULT 0 NOT NULL,
    last_check datetime DEFAULT NULL,
    screenshot_path varchar(512) DEFAULT '' NOT NULL,
    error_message text DEFAULT '' NOT NULL,
    tstamp int(11) DEFAULT 0 NOT NULL,
    crdate int(11) DEFAULT 0 NOT NULL,
    -- Page visibility fields
    page_is_visible tinyint(1) DEFAULT 1 NOT NULL,
    page_hidden tinyint(1) DEFAULT 0 NOT NULL,
    page_starttime int(11) DEFAULT 0 NOT NULL,
    page_endtime int(11) DEFAULT 0 NOT NULL,
    page_fe_group varchar(255) DEFAULT '' NOT NULL,
    page_is_visible_in_menu tinyint(1) DEFAULT 1 NOT NULL,
    page_visibility_adheres_rules tinyint(1) DEFAULT 1 NOT NULL,
    page_visibility_issues text DEFAULT '' NOT NULL,
    PRIMARY KEY (uid),
    KEY page_id (page_id),
    KEY is_online (is_online),
    KEY page_is_visible (page_is_visible),
    KEY page_visibility_adheres_rules (page_visibility_adheres_rules)
);
