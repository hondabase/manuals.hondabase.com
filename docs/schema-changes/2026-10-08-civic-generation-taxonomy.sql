-- Schema and data changes: Honda Civic generation taxonomy reorganization
-- Applied: 2026-10-08
-- Database: manuals_db

-- 1. Create generation folders for Civic (parent_id 32 = civic)
INSERT INTO folders (id, parent_id, name) VALUES
(91, 32, '1972-1979_1st_Gen'),
(92, 32, '1980-1983_2nd_Gen'),
(93, 32, '1984-1987_3rd_Gen'),
(94, 32, '1988-1991_4th_Gen'),
(95, 32, '1992-1995_5th_Gen'),
(96, 32, '1996-2000_6th_Gen'),
(97, 32, '2001-2005_7th_Gen'),
(98, 32, '2006-2011_8th_Gen'),
(99, 32, '2016-2021_10th_Gen'),
(100, 32, '2022-present_11th_Gen');

-- 2. Rename folder 42 from 2011-2016 to 2012-2015_9th_Gen
UPDATE folders SET name = '2012-2015_9th_Gen' WHERE id = 42;

-- 3. Delete obsolete folders after files moved to generation folders
DELETE FROM folders WHERE id IN (33, 34, 35, 36, 37, 38, 39, 40, 41);
