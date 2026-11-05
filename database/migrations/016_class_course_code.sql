-- Day 6: the Teacher classes screen asks for a course code (e.g. IT301) separate from the class name.
ALTER TABLE classes ADD COLUMN course_code VARCHAR(30) NULL AFTER name;
