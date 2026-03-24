-- =====================================================
-- Nkoranza SHTs E-Voting System - Complete Schema
-- With 9-Character Simple ID Format (e.g., GSC230001)
-- =====================================================

-- Create database if not exists
CREATE DATABASE IF NOT EXISTS nkoranza_voting 
CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

USE nkoranza_voting;

-- =====================================================
-- DEPARTMENT CODES TABLE
-- Stores department codes for ID generation
-- =====================================================
CREATE TABLE IF NOT EXISTS department_codes (
    id INT AUTO_INCREMENT PRIMARY KEY COMMENT 'Unique department code ID',
    department_name VARCHAR(50) NOT NULL UNIQUE COMMENT 'Full department name',
    code VARCHAR(5) NOT NULL UNIQUE COMMENT 'Department code (e.g., GSC, GAR, BUS)',
    description TEXT NULL COMMENT 'Department description',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Record creation date',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',
    
    INDEX idx_dept_codes_name (department_name),
    INDEX idx_dept_codes_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Department codes for ID generation';

-- Insert Nkoranza SHTs department codes
INSERT IGNORE INTO department_codes (department_name, code, description) VALUES
('General Science', 'GSC', 'General Science Department'),
('General Arts', 'GAR', 'General Arts Department'),
('Business', 'BUS', 'Business Department'),
('Home Economics', 'HEC', 'Home Economics Department'),
('Visual Arts', 'VAR', 'Visual Arts Department'),
('Technical', 'TEC', 'Technical Department'),
('Agriculture', 'AGR', 'Agricultural Science Department'),
('Vocational', 'VOC', 'Vocational Skills Department');

-- =====================================================
-- USERS TABLE
-- Stores all system users (voters and admins)
-- Format: [DEPT-CODE][ENTRY-YEAR][4-DIGIT-SEQUENCE]
-- Example: GSC230001 (General Science, 2023 entry, student 0001)
-- =====================================================
CREATE TABLE IF NOT EXISTS users (
    id VARCHAR(20) PRIMARY KEY COMMENT 'Unique student ID (e.g., GSC230001) - 3 letters + 2 digits + 4 digits',
    password VARCHAR(255) NOT NULL COMMENT 'Bcrypt hashed password',
    first_name VARCHAR(50) NOT NULL COMMENT 'User first name',
    last_name VARCHAR(50) NOT NULL COMMENT 'User last name',
    department VARCHAR(50) NOT NULL COMMENT 'Academic department',
    level VARCHAR(20) NOT NULL COMMENT 'Class level (e.g., 1A1, 2AG1, 300)',
    email VARCHAR(100) NOT NULL UNIQUE COMMENT 'Email address',
    entry_year INT NOT NULL COMMENT 'Year of admission',
    graduation_year INT NULL COMMENT 'Expected graduation year',
    graduated_at TIMESTAMP NULL COMMENT 'Graduation date',
    status ENUM('active', 'graduated', 'archived') DEFAULT 'active' COMMENT 'User status',
    is_admin BOOLEAN DEFAULT FALSE COMMENT 'Admin privileges flag',
    has_voted BOOLEAN DEFAULT FALSE COMMENT 'Has voted in current election',
    has_logged_in BOOLEAN DEFAULT FALSE COMMENT 'Has ever logged in',
    voting_year INT NULL COMMENT 'Year when last voted',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Account creation date',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',
    
    INDEX idx_users_department (department),
    INDEX idx_users_level (level),
    INDEX idx_users_entry_year (entry_year),
    INDEX idx_users_graduation_year (graduation_year),
    INDEX idx_users_has_voted (has_voted),
    INDEX idx_users_has_logged_in (has_logged_in),
    INDEX idx_users_voting_year (voting_year),
    INDEX idx_users_email (email),
    INDEX idx_users_created_at (created_at),
    INDEX idx_users_name (first_name, last_name),
    INDEX idx_users_auth (id, password, is_admin),
    INDEX idx_users_voting_status (voting_year, has_voted),
    INDEX idx_users_status (status),
    INDEX idx_users_department_year (department, entry_year),
    INDEX idx_users_graduation_status (status, graduation_year),
    FULLTEXT INDEX idx_users_search (first_name, last_name, email, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='System users and voters with 9-character ID format';

-- =====================================================
-- ELECTIONS TABLE
-- Stores all elections (past, present, future)
-- =====================================================
CREATE TABLE IF NOT EXISTS elections (
    id INT AUTO_INCREMENT PRIMARY KEY COMMENT 'Unique election ID',
    title VARCHAR(100) NOT NULL COMMENT 'Election title',
    description TEXT NULL COMMENT 'Election description',
    start_date DATETIME NOT NULL COMMENT 'Election start date/time',
    end_date DATETIME NOT NULL COMMENT 'Election end date/time',
    status ENUM('upcoming', 'active', 'ended', 'archived') DEFAULT 'upcoming' COMMENT 'Current election status',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Record creation date',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',
    
    INDEX idx_elections_start_date (start_date),
    INDEX idx_elections_end_date (end_date),
    INDEX idx_elections_status (status),
    INDEX idx_elections_dates (start_date, end_date),
    INDEX idx_elections_active (status, start_date, end_date),
    INDEX idx_elections_date_status (start_date, end_date, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Election campaigns';

-- =====================================================
-- POSITIONS TABLE
-- Stores positions/roles within each election
-- =====================================================
CREATE TABLE IF NOT EXISTS positions (
    id INT AUTO_INCREMENT PRIMARY KEY COMMENT 'Unique position ID',
    election_id INT NOT NULL COMMENT 'Associated election ID',
    name VARCHAR(100) NOT NULL COMMENT 'Position name',
    description TEXT NULL COMMENT 'Position description',
    category VARCHAR(100) NULL COMMENT 'Position category/group',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Record creation date',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',
    
    FOREIGN KEY (election_id) REFERENCES elections(id) ON DELETE CASCADE,
    UNIQUE KEY unique_position_name (election_id, name),
    INDEX idx_positions_election_id (election_id),
    INDEX idx_positions_category (category),
    INDEX idx_positions_name_category (name(50), category(50))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Election positions';

-- =====================================================
-- CANDIDATES TABLE
-- Stores candidates running for positions
-- =====================================================
CREATE TABLE IF NOT EXISTS candidates (
    id INT AUTO_INCREMENT PRIMARY KEY COMMENT 'Unique candidate ID',
    election_id INT NOT NULL COMMENT 'Associated election ID',
    position_id INT NOT NULL COMMENT 'Position being contested',
    user_id VARCHAR(20) NOT NULL COMMENT 'User ID of candidate',
    is_yes_no_candidate TINYINT(1) DEFAULT 0 COMMENT 'Yes/No vote candidate',
    manifesto TEXT NULL COMMENT 'Candidate manifesto',
    photo_path VARCHAR(255) NULL COMMENT 'Path to candidate photo',
    status ENUM('active', 'archived') DEFAULT 'active' COMMENT 'Candidate status',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Record creation date',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',
    
    FOREIGN KEY (election_id) REFERENCES elections(id) ON DELETE CASCADE,
    FOREIGN KEY (position_id) REFERENCES positions(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    
    UNIQUE KEY unique_candidate_per_position (election_id, position_id, user_id),
    INDEX idx_candidates_election_id (election_id),
    INDEX idx_candidates_position_id (position_id),
    INDEX idx_candidates_user_id (user_id),
    INDEX idx_candidates_status (status),
    INDEX idx_candidates_election_position (election_id, position_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Election candidates';

-- =====================================================
-- VOTES TABLE
-- Stores all cast votes
-- =====================================================
CREATE TABLE IF NOT EXISTS votes (
    id INT AUTO_INCREMENT PRIMARY KEY COMMENT 'Unique vote ID',
    election_id INT NOT NULL COMMENT 'Election ID',
    position_id INT NOT NULL COMMENT 'Position ID',
    candidate_id INT NULL COMMENT 'Selected candidate ID',
    voter_id VARCHAR(20) NOT NULL COMMENT 'Voter user ID',
    rejected TINYINT(1) DEFAULT 0 COMMENT 'Vote rejected flag',
    offline_synced TINYINT(1) DEFAULT 0 COMMENT 'Offline sync flag',
    verification_code VARCHAR(50) NULL COMMENT 'Vote verification code',
    sync_timestamp TIMESTAMP NULL COMMENT 'Sync timestamp',
    voting_year INT NULL COMMENT 'Year when vote was cast',
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'Vote timestamp',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Record creation date',
    
    FOREIGN KEY (election_id) REFERENCES elections(id) ON DELETE CASCADE,
    FOREIGN KEY (position_id) REFERENCES positions(id) ON DELETE CASCADE,
    FOREIGN KEY (candidate_id) REFERENCES candidates(id) ON DELETE SET NULL,
    FOREIGN KEY (voter_id) REFERENCES users(id) ON DELETE CASCADE,
    
    UNIQUE KEY unique_vote (voter_id, election_id, position_id) COMMENT 'One vote per position per voter',
    INDEX idx_votes_election_id (election_id),
    INDEX idx_votes_position_id (position_id),
    INDEX idx_votes_candidate_id (candidate_id),
    INDEX idx_votes_voter_id (voter_id),
    INDEX idx_votes_timestamp (timestamp),
    INDEX idx_votes_voting_year (voting_year),
    INDEX idx_votes_rejected (rejected),
    INDEX idx_votes_offline_synced (offline_synced),
    INDEX idx_votes_voter_election (voter_id, election_id),
    INDEX idx_votes_election_candidate (election_id, candidate_id),
    INDEX idx_votes_verification (verification_code),
    INDEX idx_votes_candidate_stats (election_id, candidate_id, rejected),
    INDEX idx_votes_year_election (voting_year, election_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Cast votes';

-- =====================================================
-- ACTIVITY LOGS TABLE
-- Tracks all user activities for audit purposes
-- =====================================================
CREATE TABLE IF NOT EXISTS activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY COMMENT 'Unique log ID',
    user_id VARCHAR(20) NOT NULL COMMENT 'User ID',
    user_name VARCHAR(255) NOT NULL COMMENT 'User full name',
    activity_type VARCHAR(50) NOT NULL COMMENT 'Type of activity',
    description TEXT NOT NULL COMMENT 'Activity description',
    ip_address VARCHAR(45) NULL COMMENT 'IP address',
    user_agent TEXT NULL COMMENT 'Browser user agent',
    details JSON NULL COMMENT 'Additional JSON details',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Log timestamp',
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    
    INDEX idx_activity_logs_user_id (user_id),
    INDEX idx_activity_logs_activity_type (activity_type),
    INDEX idx_activity_logs_created_at (created_at),
    INDEX idx_activity_logs_user_date (user_id, created_at),
    INDEX idx_activity_logs_type_date (activity_type, created_at),
    INDEX idx_activity_logs_composite (activity_type, created_at, user_id),
    FULLTEXT INDEX idx_activity_logs_search (description, user_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='User activity audit log';

-- =====================================================
-- PASSWORD RESETS TABLE
-- Tracks password reset requests
-- =====================================================
CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY COMMENT 'Unique reset ID',
    user_id VARCHAR(20) NOT NULL COMMENT 'User ID',
    token VARCHAR(64) NOT NULL COMMENT 'Reset token',
    expires_at DATETIME NOT NULL COMMENT 'Token expiry',
    used TINYINT(1) DEFAULT 0 COMMENT 'Token used flag',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Request timestamp',
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    
    UNIQUE KEY unique_token (token),
    INDEX idx_password_resets_user_id (user_id),
    INDEX idx_password_resets_token (token),
    INDEX idx_password_resets_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Password reset tokens';

-- =====================================================
-- SESSIONS TABLE
-- Manages user sessions (optional, for custom session handling)
-- =====================================================
CREATE TABLE IF NOT EXISTS sessions (
    session_id VARCHAR(128) PRIMARY KEY COMMENT 'Session ID',
    user_id VARCHAR(20) NULL COMMENT 'User ID',
    ip_address VARCHAR(45) NULL COMMENT 'IP address',
    user_agent TEXT NULL COMMENT 'Browser user agent',
    payload TEXT NOT NULL COMMENT 'Session data',
    last_activity INT NOT NULL COMMENT 'Last activity timestamp',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Session creation',
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    
    INDEX idx_sessions_user_id (user_id),
    INDEX idx_sessions_last_activity (last_activity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='User sessions';

-- =====================================================
-- SETTINGS TABLE
-- Stores system-wide configuration
-- =====================================================
CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY COMMENT 'Setting ID',
    setting_key VARCHAR(100) NOT NULL UNIQUE COMMENT 'Setting key',
    setting_value TEXT NULL COMMENT 'Setting value',
    setting_type ENUM('text', 'number', 'boolean', 'json') DEFAULT 'text' COMMENT 'Value type',
    description TEXT NULL COMMENT 'Setting description',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Creation date',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update',
    
    INDEX idx_settings_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='System settings';

-- =====================================================
-- FUNCTIONS
-- =====================================================

DELIMITER $$

-- Function to generate student ID in simple format: [DEPT-CODE][ENTRY-YEAR][4-DIGIT-SEQUENCE]
DROP FUNCTION IF EXISTS generate_student_id$$
CREATE FUNCTION generate_student_id(
    p_department VARCHAR(50),
    p_entry_year INT
) RETURNS VARCHAR(20)
DETERMINISTIC
BEGIN
    DECLARE dept_code VARCHAR(5);
    DECLARE entry_year_last2 CHAR(2);
    DECLARE next_seq INT;
    DECLARE sequence_num CHAR(4);
    
    -- Get department code
    SELECT code INTO dept_code 
    FROM department_codes 
    WHERE department_name = p_department;
    
    -- Default if department not found
    IF dept_code IS NULL THEN
        SET dept_code = 'OTH';
    END IF;
    
    -- Last 2 digits of entry year
    SET entry_year_last2 = RIGHT(p_entry_year, 2);
    
    -- Get next sequence number for this department and year
    SELECT COALESCE(MAX(CAST(RIGHT(id, 4) AS UNSIGNED)), 0) + 1 
    INTO next_seq
    FROM users 
    WHERE id LIKE CONCAT(dept_code, entry_year_last2, '%') AND LENGTH(id) >= 9;
    
    -- Format sequence with leading zeros (4 digits)
    SET sequence_num = LPAD(next_seq, 4, '0');
    
    RETURN CONCAT(dept_code, entry_year_last2, sequence_num);
END$$

-- Function to extract department from ID
DROP FUNCTION IF EXISTS get_department_from_id$$
CREATE FUNCTION get_department_from_id(p_user_id VARCHAR(20))
RETURNS VARCHAR(50)
DETERMINISTIC
BEGIN
    DECLARE dept_code VARCHAR(5);
    DECLARE dept_name VARCHAR(50);
    
    -- Extract first 3 characters as department code
    SET dept_code = LEFT(p_user_id, 3);
    
    -- Get department name
    SELECT department_name INTO dept_name
    FROM department_codes
    WHERE code = dept_code;
    
    RETURN COALESCE(dept_name, 'Unknown');
END$$

-- Function to extract entry year from ID
DROP FUNCTION IF EXISTS get_entry_year_from_id$$
CREATE FUNCTION get_entry_year_from_id(p_user_id VARCHAR(20))
RETURNS INT
DETERMINISTIC
BEGIN
    DECLARE entry_year_last2 CHAR(2);
    DECLARE full_year INT;
    
    -- Extract characters 4-5 as entry year last 2 digits
    SET entry_year_last2 = SUBSTRING(p_user_id, 4, 2);
    
    -- Assume 2000s
    SET full_year = CONCAT('20', entry_year_last2);
    
    RETURN full_year;
END$$

-- Function to extract sequence number from ID
DROP FUNCTION IF EXISTS get_sequence_from_id$$
CREATE FUNCTION get_sequence_from_id(p_user_id VARCHAR(20))
RETURNS INT
DETERMINISTIC
BEGIN
    -- Extract last 4 characters as sequence number
    RETURN CAST(RIGHT(p_user_id, 4) AS UNSIGNED);
END$$

DELIMITER ;

-- =====================================================
-- TRIGGERS
-- =====================================================

DELIMITER $$

-- Drop existing triggers if they exist
DROP TRIGGER IF EXISTS set_vote_voting_year$$
DROP TRIGGER IF EXISTS update_user_voting_status$$
DROP TRIGGER IF EXISTS auto_detect_yes_no_candidate$$
DROP TRIGGER IF EXISTS validate_user_id_format$$

-- Before insert trigger to validate user ID format (9 characters: 3 letters + 2 digits + 4 digits)
CREATE TRIGGER validate_user_id_format
BEFORE INSERT ON users
FOR EACH ROW
BEGIN
    -- Check if ID matches the 9-character format (3 letters, 2 digits, 4 digits)
    IF NEW.is_admin = 0 AND NEW.id NOT REGEXP '^[A-Z]{3}[0-9]{2}[0-9]{4}$' THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Invalid ID format. Must be 3 letters followed by 6 digits (9 characters total) - e.g., GSC230001';
    END IF;
    
    -- Auto-set entry_year from ID if not provided
    IF NEW.is_admin = 0 AND NEW.entry_year IS NULL THEN
        SET NEW.entry_year = get_entry_year_from_id(NEW.id);
    END IF;
END$$

-- Before insert trigger to set voting_year in votes table
CREATE TRIGGER set_vote_voting_year
BEFORE INSERT ON votes
FOR EACH ROW
BEGIN
    IF NEW.voting_year IS NULL THEN
        SET NEW.voting_year = YEAR(NEW.timestamp);
    END IF;
END$$

-- After insert trigger to update user's voting status
CREATE TRIGGER update_user_voting_status
AFTER INSERT ON votes
FOR EACH ROW
BEGIN
    UPDATE users 
    SET has_voted = 1, 
        voting_year = NEW.voting_year
    WHERE id = NEW.voter_id;
END$$

-- Trigger to automatically detect yes/no candidates on insert
CREATE TRIGGER auto_detect_yes_no_candidate
BEFORE INSERT ON candidates
FOR EACH ROW
BEGIN
    DECLARE pos_name VARCHAR(100);
    DECLARE pos_category VARCHAR(100);
    
    -- Get position details
    SELECT name, category INTO pos_name, pos_category
    FROM positions WHERE id = NEW.position_id;
    
    -- Check if this is a yes/no position
    IF pos_name REGEXP 'approve|ratify|confirm|endorse|support|accept|approval|confidence|impeachment|recall|referendum|motion|proposal|resolution|vote of|yes/no|yes or no'
       OR (pos_category IS NOT NULL AND pos_category REGEXP 'referendum|motion|approval|confidence|yes/no') THEN
        SET NEW.is_yes_no_candidate = 1;
    END IF;
END$$

DELIMITER ;

-- =====================================================
-- STORED PROCEDURES
-- =====================================================

DELIMITER $$

-- Procedure to register a new student with auto-generated ID
DROP PROCEDURE IF EXISTS register_new_student$$
CREATE PROCEDURE register_new_student(
    IN p_first_name VARCHAR(50),
    IN p_last_name VARCHAR(50),
    IN p_department VARCHAR(50),
    IN p_level VARCHAR(20),
    IN p_email VARCHAR(100),
    IN p_entry_year INT,
    IN p_graduation_year INT,
    IN p_password VARCHAR(255)
)
BEGIN
    DECLARE new_id VARCHAR(20);
    DECLARE exit handler for sqlexception
    BEGIN
        ROLLBACK;
        SELECT 'Error: Failed to register student' AS message;
    END;
    
    START TRANSACTION;
    
    -- Generate user ID (9-character format)
    SET new_id = generate_student_id(p_department, p_entry_year);
    
    -- Insert new user
    INSERT INTO users (
        id, 
        password, 
        first_name, 
        last_name, 
        department, 
        level, 
        email, 
        entry_year,
        graduation_year,
        is_admin,
        has_voted,
        has_logged_in,
        status
    ) VALUES (
        new_id,
        p_password,
        p_first_name,
        p_last_name,
        p_department,
        p_level,
        p_email,
        p_entry_year,
        p_graduation_year,
        FALSE,
        FALSE,
        FALSE,
        'active'
    );
    
    -- Log the registration
    INSERT INTO activity_logs (
        user_id, 
        user_name, 
        activity_type, 
        description, 
        details
    ) VALUES (
        new_id,
        CONCAT(p_first_name, ' ', p_last_name),
        'user_registration',
        CONCAT('New student registered with ID: ', new_id),
        JSON_OBJECT(
            'department', p_department,
            'entry_year', p_entry_year,
            'graduation_year', p_graduation_year
        )
    );
    
    COMMIT;
    
    -- Return the generated ID
    SELECT new_id AS user_id, 'Student registered successfully' AS message;
END$$

-- Procedure to reset voting for new election year
DROP PROCEDURE IF EXISTS reset_voting_for_new_year$$
CREATE PROCEDURE reset_voting_for_new_year(IN target_year INT)
BEGIN
    DECLARE reset_count INT;
    DECLARE vote_count INT;
    
    START TRANSACTION;
    
    -- Get count of votes for the year being reset (for logging)
    SELECT COUNT(*) INTO vote_count FROM votes WHERE voting_year = target_year;
    
    -- Reset user voting status
    UPDATE users 
    SET has_voted = 0, 
        has_logged_in = 0,
        voting_year = NULL 
    WHERE is_admin = 0;
    
    SET reset_count = ROW_COUNT();
    
    -- Log the activity
    INSERT INTO activity_logs (user_id, user_name, activity_type, description, details)
    VALUES ('Admin', 'System Administrator', 'system_reset', 
            CONCAT('Reset voting for ', reset_count, ' users for year ', target_year),
            JSON_OBJECT(
                'target_year', target_year,
                'users_reset', reset_count,
                'votes_archived', vote_count
            ));
    
    COMMIT;
    
    -- Return result
    SELECT CONCAT('Reset ', reset_count, ' voters for year ', target_year, 
                  '. Archived ', vote_count, ' votes.') AS message;
END$$

-- Procedure to archive graduated students
DROP PROCEDURE IF EXISTS archive_graduated_students$$
CREATE PROCEDURE archive_graduated_students()
BEGIN
    DECLARE archived_count INT;
    
    UPDATE users 
    SET status = 'archived' 
    WHERE graduation_year IS NOT NULL 
    AND graduation_year <= YEAR(CURDATE())
    AND is_admin = 0;
    
    SET archived_count = ROW_COUNT();
    
    INSERT INTO activity_logs (user_id, user_name, activity_type, description, details)
    VALUES ('Admin', 'System Administrator', 'system_archive', 
            CONCAT('Archived ', archived_count, ' graduated students'),
            JSON_OBJECT('year', YEAR(CURDATE()), 'count', archived_count));
END$$

-- Procedure to get voting statistics by year
DROP PROCEDURE IF EXISTS get_voting_stats_by_year$$
CREATE PROCEDURE get_voting_stats_by_year(IN year_param INT)
BEGIN
    SELECT 
        year_param AS election_year,
        COUNT(DISTINCT v.voter_id) AS total_voters,
        COUNT(v.id) AS total_votes,
        COUNT(DISTINCT v.election_id) AS elections_participated,
        ROUND(COUNT(v.id) / COUNT(DISTINCT v.voter_id), 2) AS avg_votes_per_voter
    FROM votes v
    WHERE v.voting_year = year_param;
    
    -- Get top voters by year
    SELECT 
        u.id,
        u.first_name,
        u.last_name,
        u.department,
        u.level,
        COUNT(v.id) AS votes_cast
    FROM users u
    JOIN votes v ON u.id = v.voter_id
    WHERE v.voting_year = year_param AND u.is_admin = 0
    GROUP BY u.id
    ORDER BY votes_cast DESC
    LIMIT 10;
END$$

-- Procedure to sync user voting years
DROP PROCEDURE IF EXISTS sync_user_voting_years$$
CREATE PROCEDURE sync_user_voting_years()
BEGIN
    -- Update users based on their votes
    UPDATE users u
    JOIN (
        SELECT 
            voter_id,
            MAX(YEAR(timestamp)) as latest_vote_year
        FROM votes
        GROUP BY voter_id
    ) v ON u.id = v.voter_id
    SET 
        u.has_voted = 1,
        u.voting_year = v.latest_vote_year
    WHERE u.is_admin = 0;
    
    -- Reset users with no votes
    UPDATE users 
    SET has_voted = 0,
        voting_year = NULL
    WHERE is_admin = 0 
    AND id NOT IN (SELECT DISTINCT voter_id FROM votes);
    
    SELECT CONCAT('Synced voting years for users') as message;
END$$

-- Procedure to update existing yes/no candidates
DROP PROCEDURE IF EXISTS update_yes_no_candidates$$
CREATE PROCEDURE update_yes_no_candidates()
BEGIN
    DECLARE updated_count INT;
    
    -- Update candidates based on position names
    UPDATE candidates c
    JOIN positions p ON c.position_id = p.id
    SET c.is_yes_no_candidate = 1
    WHERE 
        LOWER(p.name) REGEXP 'approve|ratify|confirm|endorse|support|accept|approval|confidence|impeachment|recall|referendum|motion|proposal|resolution|vote of|yes/no|yes or no'
        OR (
            p.category IS NOT NULL 
            AND LOWER(p.category) REGEXP 'referendum|motion|approval|confidence|yes/no'
        );
    
    SET updated_count = ROW_COUNT();
    
    -- Log the activity
    INSERT INTO activity_logs (user_id, user_name, activity_type, description, details)
    VALUES ('Admin', 'System Administrator', 'auto_detect', 
            CONCAT('Auto-detected ', updated_count, ' yes/no candidates'),
            JSON_OBJECT('count', updated_count, 'timestamp', NOW()));
    
    SELECT CONCAT('✅ Updated ', updated_count, ' candidates as yes/no candidates') AS message;
END$$

-- Procedure to check voting eligibility
DROP PROCEDURE IF EXISTS check_voting_eligibility$$
CREATE PROCEDURE check_voting_eligibility(
    IN p_user_id VARCHAR(20),
    IN p_election_year INT
)
BEGIN
    DECLARE v_graduation_year INT;
    DECLARE v_status ENUM('active', 'archived');
    DECLARE v_has_voted BOOLEAN;
    
    -- Get user details
    SELECT graduation_year, status, has_voted 
    INTO v_graduation_year, v_status, v_has_voted
    FROM users 
    WHERE id = p_user_id;
    
    -- Check eligibility
    SELECT 
        p_user_id AS user_id,
        CASE 
            WHEN v_status = 'archived' THEN FALSE
            WHEN v_graduation_year IS NOT NULL AND v_graduation_year < p_election_year THEN FALSE
            WHEN v_has_voted = 1 THEN FALSE
            ELSE TRUE
        END AS is_eligible,
        CASE 
            WHEN v_status = 'archived' THEN 'Account archived'
            WHEN v_graduation_year IS NOT NULL AND v_graduation_year < p_election_year THEN 'Graduated'
            WHEN v_has_voted = 1 THEN 'Already voted'
            ELSE 'Eligible to vote'
        END AS eligibility_reason;
END$$

-- Procedure to cleanup old data
DROP PROCEDURE IF EXISTS cleanup_old_data$$
CREATE PROCEDURE cleanup_old_data(IN years_to_keep INT)
BEGIN
    DECLARE cutoff_year INT;
    DECLARE deleted_votes INT;
    DECLARE deleted_logs INT;
    
    SET cutoff_year = YEAR(CURDATE()) - years_to_keep;
    
    START TRANSACTION;
    
    -- Delete old votes
    DELETE FROM votes WHERE voting_year < cutoff_year;
    SET deleted_votes = ROW_COUNT();
    
    -- Clean old activity logs
    DELETE FROM activity_logs WHERE YEAR(created_at) < cutoff_year;
    SET deleted_logs = ROW_COUNT();
    
    INSERT INTO activity_logs (user_id, user_name, activity_type, description, details)
    VALUES ('Admin', 'System Administrator', 'system_cleanup', 
            CONCAT('Cleaned up data older than ', cutoff_year),
            JSON_OBJECT(
                'cutoff_year', cutoff_year,
                'deleted_votes', deleted_votes,
                'deleted_logs', deleted_logs
            ));
    
    COMMIT;
    
    SELECT CONCAT('Cleaned up data older than ', cutoff_year, 
                  '. Removed ', deleted_votes, ' votes and ', deleted_logs, ' logs.') AS message;
END$$

DELIMITER ;

-- =====================================================
-- VIEWS
-- =====================================================

-- View for current active election
CREATE OR REPLACE VIEW current_election AS
SELECT * FROM elections 
WHERE status = 'active' 
ORDER BY start_date DESC 
LIMIT 1;

-- View for election results
CREATE OR REPLACE VIEW election_results AS
SELECT 
    e.id AS election_id,
    e.title AS election_title,
    p.id AS position_id,
    p.name AS position_name,
    c.id AS candidate_id,
    u.first_name,
    u.last_name,
    COUNT(v.id) AS vote_count,
    (SELECT COUNT(*) FROM votes v2 WHERE v2.position_id = p.id AND v2.rejected = 0) AS total_position_votes
FROM elections e
JOIN positions p ON e.id = p.election_id
LEFT JOIN candidates c ON p.id = c.position_id
LEFT JOIN users u ON c.user_id = u.id
LEFT JOIN votes v ON c.id = v.candidate_id AND v.rejected = 0
GROUP BY e.id, p.id, c.id, u.first_name, u.last_name
ORDER BY e.id DESC, p.id, vote_count DESC;

-- View for voter statistics
CREATE OR REPLACE VIEW voter_statistics AS
SELECT 
    COUNT(DISTINCT id) AS total_voters,
    SUM(has_voted) AS total_voted,
    COUNT(*) - SUM(has_voted) AS total_not_voted,
    SUM(has_logged_in) AS total_logged_in,
    SUM(CASE WHEN graduation_year IS NOT NULL AND graduation_year <= YEAR(CURDATE()) THEN 1 ELSE 0 END) AS total_graduated,
    ROUND((SUM(has_voted) / COUNT(*)) * 100, 2) AS participation_rate
FROM users
WHERE is_admin = 0;

-- View for yearly voting summary
CREATE OR REPLACE VIEW yearly_voting_summary AS
SELECT 
    v.voting_year,
    COUNT(DISTINCT v.voter_id) AS unique_voters,
    COUNT(v.id) AS total_votes,
    COUNT(DISTINCT v.election_id) AS elections_held,
    ROUND(COUNT(v.id) / COUNT(DISTINCT v.voter_id), 2) AS avg_votes_per_voter
FROM votes v
WHERE v.voting_year IS NOT NULL
GROUP BY v.voting_year
ORDER BY v.voting_year DESC;

-- View for active voters (not graduated)
CREATE OR REPLACE VIEW active_voters AS
SELECT * FROM users 
WHERE is_admin = 0 
AND (graduation_year IS NULL OR graduation_year > YEAR(CURDATE()))
AND status = 'active';

-- View for yes/no candidates
CREATE OR REPLACE VIEW yes_no_candidates AS
SELECT 
    c.id,
    u.first_name,
    u.last_name,
    p.name AS position_name,
    e.title AS election_title,
    c.manifesto
FROM candidates c
JOIN users u ON c.user_id = u.id
JOIN positions p ON c.position_id = p.id
JOIN elections e ON c.election_id = e.id
WHERE c.is_yes_no_candidate = 1 AND c.status = 'active';

-- View for students by entry cohort
CREATE OR REPLACE VIEW students_by_cohort AS
SELECT 
    entry_year,
    department,
    COUNT(*) as student_count,
    GROUP_CONCAT(id) as student_ids
FROM users
WHERE is_admin = 0
GROUP BY entry_year, department
ORDER BY entry_year DESC, department;

-- View for ID format validation
CREATE OR REPLACE VIEW id_format_check AS
SELECT 
    id,
    LEFT(id, 3) as dept_code,
    SUBSTRING(id, 4, 2) as entry_year_code,
    RIGHT(id, 4) as sequence_num,
    CONCAT('20', SUBSTRING(id, 4, 2)) as entry_year,
    get_department_from_id(id) as department_name,
    CASE 
        WHEN id REGEXP '^[A-Z]{3}[0-9]{2}[0-9]{4}$' THEN '✅ Valid (9 chars)'
        ELSE '❌ Invalid'
    END as format_status
FROM users
WHERE is_admin = 0;

-- =====================================================
-- DEFAULT DATA
-- =====================================================

-- Insert default admin user (password: Admin@123)
-- Admin uses 9-character format: ADM + 00 + 01 = ADM0001
INSERT IGNORE INTO users (id, password, first_name, last_name, department, level, email, entry_year, is_admin, has_voted, has_logged_in, status) 
VALUES (
    'Admin',
    '$2y$10$fJN/438LcnJgMcaXiTBvBuCypkvm.aD.iZUf8/VMwe9NwnAnO86C2', -- bcrypt hash of 'Admin@123'
    'System',
    'Administrator',
    'Administration',
    'Senior',
    'admin@nkoranzashts.edu.gh',
    2025,
    TRUE,
    FALSE,
    TRUE,
    'active'
);

-- Insert default system settings
INSERT IGNORE INTO settings (setting_key, setting_value, setting_type, description) VALUES
('site_name', 'Nkoranza SHTs E-Voting System', 'text', 'Website name'),
('site_description', 'Secure electronic voting platform for Nkoranza Senior High Technical School', 'text', 'Site description'),
('contact_email', 'elections@nkoranzashts.edu.gh', 'text', 'Contact email address'),
('voting_start_time', '08:00:00', 'text', 'Default voting start time'),
('voting_end_time', '17:00:00', 'text', 'Default voting end time'),
('allow_offline_voting', 'true', 'boolean', 'Allow offline voting'),
('require_verification', 'true', 'boolean', 'Require vote verification'),
('max_login_attempts', '5', 'number', 'Maximum login attempts before lockout'),
('session_timeout', '3600', 'number', 'Session timeout in seconds'),
('maintenance_mode', 'false', 'boolean', 'System maintenance mode'),
('current_academic_year', '2025', 'number', 'Current academic year'),
('id_format', 'DEPT+YY+NNNN', 'text', 'User ID format: 3 letters + 2 digits + 4 digits (9 chars total)');

-- =====================================================
-- VERIFICATION QUERIES (commented out - run separately)
-- =====================================================

/*

-- Check all tables exist
SELECT 'DATABASE SETUP VERIFICATION' as '';

-- Show all tables
SHOW TABLES;

-- Check department codes
SELECT * FROM department_codes;

-- Verify ID formats
SELECT 'ID Format Check:' as '';
SELECT 
    id,
    LEFT(id, 3) as department_code,
    CONCAT('20', SUBSTRING(id, 4, 2)) as entry_year,
    RIGHT(id, 4) as sequence_number,
    CASE 
        WHEN id REGEXP '^[A-Z]{3}[0-9]{2}[0-9]{4}$' THEN '✅ Valid (9 chars)'
        ELSE '❌ Invalid'
    END as format_status
FROM users
WHERE is_admin = 0;

-- Test ID generation
SELECT generate_student_id('General Science', 2025) as new_gsc_id;
SELECT generate_student_id('Business', 2025) as new_bus_id;

-- Check activity logs
SELECT * FROM activity_logs ORDER BY id DESC LIMIT 10;

*/

-- =====================================================
-- MIGRATION: Multi-year election support
-- Run this once if upgrading from a single-year setup.
-- These columns already exist in schema3 — this block
-- is safe to re-run (uses ALTER IGNORE / IF NOT EXISTS).
-- =====================================================

-- Ensure voting_year column exists on users
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'users'
      AND COLUMN_NAME  = 'voting_year'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE users ADD COLUMN voting_year INT NULL COMMENT \'Year when last voted\' AFTER has_logged_in',
    'SELECT \'voting_year already exists\' AS migration_note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Ensure voting_year column exists on votes
SET @col2_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'votes'
      AND COLUMN_NAME  = 'voting_year'
);
SET @sql2 = IF(@col2_exists = 0,
    'ALTER TABLE votes ADD COLUMN voting_year INT NULL COMMENT \'Year when vote was cast\' AFTER offline_synced',
    'SELECT \'votes.voting_year already exists\' AS migration_note'
);
PREPARE stmt2 FROM @sql2; EXECUTE stmt2; DEALLOCATE PREPARE stmt2;

-- Back-fill voting_year on existing votes from the vote timestamp
UPDATE votes SET voting_year = YEAR(timestamp) WHERE voting_year IS NULL;

-- Back-fill voting_year on users who have voted (from their most recent vote)
UPDATE users u
JOIN (
    SELECT voter_id, MAX(YEAR(timestamp)) AS latest_year
    FROM votes
    GROUP BY voter_id
) v ON u.id = v.voter_id
SET u.voting_year = v.latest_year
WHERE u.voting_year IS NULL AND u.has_voted = 1;

-- =====================================================
-- To start a new election year from MySQL directly:
--   CALL reset_voting_for_new_year(YEAR(NOW()));
-- Or use the Settings > New Election Year panel in the admin UI.
-- =====================================================
