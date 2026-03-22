-- SnapSmack Migration 075a
-- Creates snap_blogroll_cats table (was missing from initial schema)
--
-- Run once on any install that has snap_blogroll but not snap_blogroll_cats.
-- Safe to run on fresh installs too — uses CREATE TABLE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS `snap_blogroll_cats` (
    `id`       INT          NOT NULL AUTO_INCREMENT,
    `cat_name` VARCHAR(100) NOT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
