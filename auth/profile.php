<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit();
}

// Initialize theme if not set
if (!isset($_SESSION['theme'])) {
    $_SESSION['theme'] = 'light';
}

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME_CODESPHERE', 'codesphere');
define('DB_NAME_USER_REPOS', 'user_repos');
define('DB_NAME_PROFILES', 'profiles');

// Database connection function
function getDBConnection($dbName) {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, $dbName);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    return $conn;
}

// Create profiles database and table if they don't exist
function initializeProfilesDB() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    
    // Create database if it doesn't exist
    $conn->query("CREATE DATABASE IF NOT EXISTS " . DB_NAME_PROFILES);
    $conn->select_db(DB_NAME_PROFILES);
    
    // Create profiles table if it doesn't exist with all required columns
    $createTableSQL = "
    CREATE TABLE IF NOT EXISTS user_profiles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id VARCHAR(50) UNIQUE NOT NULL,
        username VARCHAR(100) NOT NULL,
        bio TEXT,
        location VARCHAR(100),
        website VARCHAR(255),
        company VARCHAR(100),
        job_title VARCHAR(100),
        skills TEXT,
        skill_levels TEXT,
        interests TEXT,
        hobbies TEXT,
        languages TEXT,
        language_levels TEXT,
        education TEXT,
        work_experience TEXT,
        certifications TEXT,
        projects TEXT,
        achievements TEXT,
        publications TEXT,
        volunteer_work TEXT,
        mentorship TEXT,
        learning_goals TEXT,
        availability_status VARCHAR(20) DEFAULT 'available',
        hourly_rate DECIMAL(10,2),
        github_url VARCHAR(255),
        twitter_url VARCHAR(255),
        linkedin_url VARCHAR(255),
        portfolio_url VARCHAR(255),
        avatar_color VARCHAR(7) DEFAULT '#6366f1',
        theme_preference VARCHAR(10) DEFAULT 'auto',
        is_public BOOLEAN DEFAULT TRUE,
        is_hireable BOOLEAN DEFAULT FALSE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )";
    
    if (!$conn->query($createTableSQL)) {
        die("Error creating table: " . $conn->error);
    }
    
    // Add any missing columns
    $columnsToAdd = [
        'skills' => 'TEXT',
        'skill_levels' => 'TEXT',
        'interests' => 'TEXT',
        'hobbies' => 'TEXT',
        'languages' => 'TEXT',
        'language_levels' => 'TEXT',
        'education' => 'TEXT',
        'work_experience' => 'TEXT',
        'certifications' => 'TEXT',
        'projects' => 'TEXT',
        'achievements' => 'TEXT',
        'publications' => 'TEXT',
        'volunteer_work' => 'TEXT',
        'mentorship' => 'TEXT',
        'learning_goals' => 'TEXT',
        'availability_status' => 'VARCHAR(20) DEFAULT "available"',
        'hourly_rate' => 'DECIMAL(10,2)',
        'github_url' => 'VARCHAR(255)',
        'twitter_url' => 'VARCHAR(255)',
        'linkedin_url' => 'VARCHAR(255)',
        'portfolio_url' => 'VARCHAR(255)',
        'avatar_color' => 'VARCHAR(7) DEFAULT "#6366f1"',
        'theme_preference' => 'VARCHAR(10) DEFAULT "auto"',
        'is_public' => 'BOOLEAN DEFAULT TRUE',
        'is_hireable' => 'BOOLEAN DEFAULT FALSE'
    ];
    
    foreach ($columnsToAdd as $column => $definition) {
        $result = $conn->query("SHOW COLUMNS FROM user_profiles LIKE '$column'");
        if ($result->num_rows == 0) {
            $alterSQL = "ALTER TABLE user_profiles ADD COLUMN $column $definition";
            if (!$conn->query($alterSQL)) {
                error_log("Failed to add column $column: " . $conn->error);
            }
        }
    }
    
    $conn->close();
}

// Initialize profiles database
initializeProfilesDB();

// Get user data
$conn = getDBConnection(DB_NAME_CODESPHERE);
$stmt = $conn->prepare("SELECT username, email, first_name, last_name, created_at FROM users WHERE userId = ?");
$stmt->bind_param("s", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

// Get or create user profile
$profileConn = getDBConnection(DB_NAME_PROFILES);
$profileStmt = $profileConn->prepare("SELECT * FROM user_profiles WHERE user_id = ?");
$profileStmt->bind_param("s", $_SESSION['user_id']);
$profileStmt->execute();
$profileResult = $profileStmt->get_result();
$userProfile = $profileResult->fetch_assoc();

// If profile doesn't exist, create one
if (!$userProfile) {
    $insertStmt = $profileConn->prepare("
        INSERT INTO user_profiles (user_id, username, bio, avatar_color) 
        VALUES (?, ?, ?, ?)
    ");
    $defaultBio = "Passionate developer building amazing things with code.";
    $defaultColor = '#6366f1';
    $insertStmt->bind_param("ssss", $_SESSION['user_id'], $user['username'], $defaultBio, $defaultColor);
    $insertStmt->execute();
    
    // Fetch the newly created profile
    $profileStmt->execute();
    $profileResult = $profileStmt->get_result();
    $userProfile = $profileResult->fetch_assoc();
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        // Get all form data with proper defaults
        $bio = $_POST['bio'] ?? '';
        $location = $_POST['location'] ?? '';
        $website = $_POST['website'] ?? '';
        $company = $_POST['company'] ?? '';
        $job_title = $_POST['job_title'] ?? '';
        $skills = $_POST['skills'] ?? '';
        $skill_levels = $_POST['skill_levels'] ?? '';
        $interests = $_POST['interests'] ?? '';
        $hobbies = $_POST['hobbies'] ?? '';
        $languages = $_POST['languages'] ?? '';
        $language_levels = $_POST['language_levels'] ?? '';
        $education = $_POST['education'] ?? '';
        $work_experience = $_POST['work_experience'] ?? '';
        $certifications = $_POST['certifications'] ?? '';
        $projects = $_POST['projects'] ?? '';
        $achievements = $_POST['achievements'] ?? '';
        $publications = $_POST['publications'] ?? '';
        $volunteer_work = $_POST['volunteer_work'] ?? '';
        $mentorship = $_POST['mentorship'] ?? '';
        $learning_goals = $_POST['learning_goals'] ?? '';
        $availability_status = $_POST['availability_status'] ?? 'available';
        $hourly_rate = isset($_POST['hourly_rate']) ? floatval($_POST['hourly_rate']) : 0;
        $github_url = $_POST['github_url'] ?? '';
        $twitter_url = $_POST['twitter_url'] ?? '';
        $linkedin_url = $_POST['linkedin_url'] ?? '';
        $portfolio_url = $_POST['portfolio_url'] ?? '';
        $avatar_color = $_POST['avatar_color'] ?? '#6366f1';
        $theme_preference = $_POST['theme_preference'] ?? 'auto';
        $is_public = isset($_POST['is_public']) ? 1 : 0;
        $is_hireable = isset($_POST['is_hireable']) ? 1 : 0;
        
        // Build the update query dynamically based on available columns
        $updateFields = [];
        $updateTypes = "";
        $updateValues = [];
        
        // List of all possible fields
        $allFields = [
            'bio', 'location', 'website', 'company', 'job_title', 
            'skills', 'skill_levels', 'interests', 'hobbies', 'languages',
            'language_levels', 'education', 'work_experience', 'certifications',
            'projects', 'achievements', 'publications', 'volunteer_work',
            'mentorship', 'learning_goals', 'availability_status', 'hourly_rate',
            'github_url', 'twitter_url', 'linkedin_url', 'portfolio_url',
            'avatar_color', 'theme_preference', 'is_public', 'is_hireable'
        ];
        
        // Check which columns actually exist in the table
        $existingColumns = [];
        $columnCheck = $profileConn->query("SHOW COLUMNS FROM user_profiles");
        while ($column = $columnCheck->fetch_assoc()) {
            $existingColumns[] = $column['Field'];
        }
        
        // Build the update query with only existing columns
        foreach ($allFields as $field) {
            if (in_array($field, $existingColumns)) {
                $updateFields[] = "$field = ?";
                $updateTypes .= "s"; // Most fields are strings
                $updateValues[] = ${$field};
            }
        }
        
        // Add the user_id at the end
        $updateTypes .= "s";
        $updateValues[] = $_SESSION['user_id'];
        
        if (!empty($updateFields)) {
            $updateSQL = "UPDATE user_profiles SET " . implode(', ', $updateFields) . ", updated_at = CURRENT_TIMESTAMP WHERE user_id = ?";
            $updateStmt = $profileConn->prepare($updateSQL);
            
            if ($updateStmt) {
                $updateStmt->bind_param($updateTypes, ...$updateValues);
                
                if ($updateStmt->execute()) {
                    $successMessage = "Profile updated successfully!";
                    // Refresh profile data
                    $profileStmt->execute();
                    $profileResult = $profileStmt->get_result();
                    $userProfile = $profileResult->fetch_assoc();
                } else {
                    $errorMessage = "Error updating profile: " . $updateStmt->error;
                }
                
                $updateStmt->close();
            } else {
                $errorMessage = "Error preparing update statement: " . $profileConn->error;
            }
        } else {
            $errorMessage = "No valid fields to update";
        }
    }
    
    // Handle individual section additions
    $sectionSuccess = false;
    $sectionType = '';
    
    if (isset($_POST['add_skill'])) {
        $newSkill = trim($_POST['new_skill'] ?? '');
        $skillLevel = $_POST['skill_level'] ?? 'intermediate';
        
        if (!empty($newSkill)) {
            $currentSkills = !empty($userProfile['skills']) ? explode(',', $userProfile['skills']) : [];
            if (!in_array($newSkill, $currentSkills)) {
                $currentSkills[] = $newSkill;
                $updatedSkills = implode(',', $currentSkills);
                
                // Update skill levels
                $skillLevels = !empty($userProfile['skill_levels']) ? json_decode($userProfile['skill_levels'], true) : [];
                $skillLevels[$newSkill] = $skillLevel;
                $updatedSkillLevels = json_encode($skillLevels);
                
                // Check if columns exist before updating
                $columnCheck = $profileConn->query("SHOW COLUMNS FROM user_profiles LIKE 'skills'");
                if ($columnCheck->num_rows > 0) {
                    $updateStmt = $profileConn->prepare("UPDATE user_profiles SET skills = ? WHERE user_id = ?");
                    $updateStmt->bind_param("ss", $updatedSkills, $_SESSION['user_id']);
                    if ($updateStmt->execute()) {
                        $sectionSuccess = true;
                        $sectionType = 'skill';
                    }
                    $updateStmt->close();
                    
                    // Update skill levels if column exists
                    $columnCheck = $profileConn->query("SHOW COLUMNS FROM user_profiles LIKE 'skill_levels'");
                    if ($columnCheck->num_rows > 0) {
                        $updateStmt = $profileConn->prepare("UPDATE user_profiles SET skill_levels = ? WHERE user_id = ?");
                        $updateStmt->bind_param("ss", $updatedSkillLevels, $_SESSION['user_id']);
                        $updateStmt->execute();
                        $updateStmt->close();
                    }
                }
            }
        }
    }
    
    if (isset($_POST['add_certification'])) {
        $newCert = trim($_POST['new_certification'] ?? '');
        if (!empty($newCert)) {
            $currentCerts = !empty($userProfile['certifications']) ? explode(',', $userProfile['certifications']) : [];
            if (!in_array($newCert, $currentCerts)) {
                $currentCerts[] = $newCert;
                $updatedCerts = implode(',', $currentCerts);
                
                // Check if column exists before updating
                $columnCheck = $profileConn->query("SHOW COLUMNS FROM user_profiles LIKE 'certifications'");
                if ($columnCheck->num_rows > 0) {
                    $updateStmt = $profileConn->prepare("UPDATE user_profiles SET certifications = ? WHERE user_id = ?");
                    $updateStmt->bind_param("ss", $updatedCerts, $_SESSION['user_id']);
                    if ($updateStmt->execute()) {
                        $sectionSuccess = true;
                        $sectionType = 'certification';
                    }
                    $updateStmt->close();
                }
            }
        }
    }
    
    if (isset($_POST['add_interest'])) {
        $newInterest = trim($_POST['new_interest'] ?? '');
        if (!empty($newInterest)) {
            $currentInterests = !empty($userProfile['interests']) ? explode(',', $userProfile['interests']) : [];
            if (!in_array($newInterest, $currentInterests)) {
                $currentInterests[] = $newInterest;
                $updatedInterests = implode(',', $currentInterests);
                
                // Check if column exists before updating
                $columnCheck = $profileConn->query("SHOW COLUMNS FROM user_profiles LIKE 'interests'");
                if ($columnCheck->num_rows > 0) {
                    $updateStmt = $profileConn->prepare("UPDATE user_profiles SET interests = ? WHERE user_id = ?");
                    $updateStmt->bind_param("ss", $updatedInterests, $_SESSION['user_id']);
                    if ($updateStmt->execute()) {
                        $sectionSuccess = true;
                        $sectionType = 'interest';
                    }
                    $updateStmt->close();
                }
            }
        }
    }
    
    if (isset($_POST['add_hobby'])) {
        $newHobby = trim($_POST['new_hobby'] ?? '');
        if (!empty($newHobby)) {
            $currentHobbies = !empty($userProfile['hobbies']) ? explode(',', $userProfile['hobbies']) : [];
            if (!in_array($newHobby, $currentHobbies)) {
                $currentHobbies[] = $newHobby;
                $updatedHobbies = implode(',', $currentHobbies);
                
                // Check if column exists before updating
                $columnCheck = $profileConn->query("SHOW COLUMNS FROM user_profiles LIKE 'hobbies'");
                if ($columnCheck->num_rows > 0) {
                    $updateStmt = $profileConn->prepare("UPDATE user_profiles SET hobbies = ? WHERE user_id = ?");
                    $updateStmt->bind_param("ss", $updatedHobbies, $_SESSION['user_id']);
                    if ($updateStmt->execute()) {
                        $sectionSuccess = true;
                        $sectionType = 'hobby';
                    }
                    $updateStmt->close();
                }
            }
        }
    }
    
    if (isset($_POST['add_language'])) {
        $newLanguage = trim($_POST['new_language'] ?? '');
        $languageLevel = $_POST['language_level'] ?? 'intermediate';
        
        if (!empty($newLanguage)) {
            $currentLanguages = !empty($userProfile['languages']) ? explode(',', $userProfile['languages']) : [];
            if (!in_array($newLanguage, $currentLanguages)) {
                $currentLanguages[] = $newLanguage;
                $updatedLanguages = implode(',', $currentLanguages);
                
                // Update language levels
                $languageLevels = !empty($userProfile['language_levels']) ? json_decode($userProfile['language_levels'], true) : [];
                $languageLevels[$newLanguage] = $languageLevel;
                $updatedLanguageLevels = json_encode($languageLevels);
                
                // Check if columns exist before updating
                $columnCheck = $profileConn->query("SHOW COLUMNS FROM user_profiles LIKE 'languages'");
                if ($columnCheck->num_rows > 0) {
                    $updateStmt = $profileConn->prepare("UPDATE user_profiles SET languages = ? WHERE user_id = ?");
                    $updateStmt->bind_param("ss", $updatedLanguages, $_SESSION['user_id']);
                    if ($updateStmt->execute()) {
                        $sectionSuccess = true;
                        $sectionType = 'language';
                    }
                    $updateStmt->close();
                    
                    // Update language levels if column exists
                    $columnCheck = $profileConn->query("SHOW COLUMNS FROM user_profiles LIKE 'language_levels'");
                    if ($columnCheck->num_rows > 0) {
                        $updateStmt = $profileConn->prepare("UPDATE user_profiles SET language_levels = ? WHERE user_id = ?");
                        $updateStmt->bind_param("ss", $updatedLanguageLevels, $_SESSION['user_id']);
                        $updateStmt->execute();
                        $updateStmt->close();
                    }
                }
            }
        }
    }
    
    if (isset($_POST['add_education'])) {
        $degree = trim($_POST['education_degree'] ?? '');
        $institution = trim($_POST['education_institution'] ?? '');
        $year = trim($_POST['education_year'] ?? '');
        
        if (!empty($degree) && !empty($institution)) {
            $newEducation = "$degree|$institution|$year";
            $currentEducation = !empty($userProfile['education']) ? explode(';', $userProfile['education']) : [];
            $currentEducation[] = $newEducation;
            $updatedEducation = implode(';', $currentEducation);
            
            // Check if column exists before updating
            $columnCheck = $profileConn->query("SHOW COLUMNS FROM user_profiles LIKE 'education'");
            if ($columnCheck->num_rows > 0) {
                $updateStmt = $profileConn->prepare("UPDATE user_profiles SET education = ? WHERE user_id = ?");
                $updateStmt->bind_param("ss", $updatedEducation, $_SESSION['user_id']);
                if ($updateStmt->execute()) {
                    $sectionSuccess = true;
                    $sectionType = 'education';
                }
                $updateStmt->close();
            }
        }
    }
    
    if (isset($_POST['add_experience'])) {
        $position = trim($_POST['experience_position'] ?? '');
        $company = trim($_POST['experience_company'] ?? '');
        $duration = trim($_POST['experience_duration'] ?? '');
        $description = trim($_POST['experience_description'] ?? '');
        
        if (!empty($position) && !empty($company)) {
            $newExperience = "$position|$company|$duration|$description";
            $currentExperience = !empty($userProfile['work_experience']) ? explode(';', $userProfile['work_experience']) : [];
            $currentExperience[] = $newExperience;
            $updatedExperience = implode(';', $currentExperience);
            
            // Check if column exists before updating
            $columnCheck = $profileConn->query("SHOW COLUMNS FROM user_profiles LIKE 'work_experience'");
            if ($columnCheck->num_rows > 0) {
                $updateStmt = $profileConn->prepare("UPDATE user_profiles SET work_experience = ? WHERE user_id = ?");
                $updateStmt->bind_param("ss", $updatedExperience, $_SESSION['user_id']);
                if ($updateStmt->execute()) {
                    $sectionSuccess = true;
                    $sectionType = 'experience';
                }
                $updateStmt->close();
            }
        }
    }
    
    if (isset($_POST['add_project'])) {
        $title = trim($_POST['project_title'] ?? '');
        $description = trim($_POST['project_description'] ?? '');
        $url = trim($_POST['project_url'] ?? '');
        $technologies = trim($_POST['project_technologies'] ?? '');
        
        if (!empty($title) && !empty($description)) {
            $newProject = "$title|$description|$url|$technologies";
            $currentProjects = !empty($userProfile['projects']) ? explode(';', $userProfile['projects']) : [];
            $currentProjects[] = $newProject;
            $updatedProjects = implode(';', $currentProjects);
            
            // Check if column exists before updating
            $columnCheck = $profileConn->query("SHOW COLUMNS FROM user_profiles LIKE 'projects'");
            if ($columnCheck->num_rows > 0) {
                $updateStmt = $profileConn->prepare("UPDATE user_profiles SET projects = ? WHERE user_id = ?");
                $updateStmt->bind_param("ss", $updatedProjects, $_SESSION['user_id']);
                if ($updateStmt->execute()) {
                    $sectionSuccess = true;
                    $sectionType = 'project';
                }
                $updateStmt->close();
            }
        }
    }
    
    if (isset($_POST['add_publication'])) {
        $title = trim($_POST['publication_title'] ?? '');
        $publisher = trim($_POST['publication_publisher'] ?? '');
        $year = trim($_POST['publication_year'] ?? '');
        $url = trim($_POST['publication_url'] ?? '');
        
        if (!empty($title) && !empty($publisher)) {
            $newPublication = "$title|$publisher|$year|$url";
            $currentPublications = !empty($userProfile['publications']) ? explode(';', $userProfile['publications']) : [];
            $currentPublications[] = $newPublication;
            $updatedPublications = implode(';', $currentPublications);
            
            // Check if column exists before updating
            $columnCheck = $profileConn->query("SHOW COLUMNS FROM user_profiles LIKE 'publications'");
            if ($columnCheck->num_rows > 0) {
                $updateStmt = $profileConn->prepare("UPDATE user_profiles SET publications = ? WHERE user_id = ?");
                $updateStmt->bind_param("ss", $updatedPublications, $_SESSION['user_id']);
                if ($updateStmt->execute()) {
                    $sectionSuccess = true;
                    $sectionType = 'publication';
                }
                $updateStmt->close();
            }
        }
    }
    
    if (isset($_POST['add_achievement'])) {
        $newAchievement = trim($_POST['new_achievement'] ?? '');
        if (!empty($newAchievement)) {
            $currentAchievements = !empty($userProfile['achievements']) ? explode(',', $userProfile['achievements']) : [];
            if (!in_array($newAchievement, $currentAchievements)) {
                $currentAchievements[] = $newAchievement;
                $updatedAchievements = implode(',', $currentAchievements);
                
                // Check if column exists before updating
                $columnCheck = $profileConn->query("SHOW COLUMNS FROM user_profiles LIKE 'achievements'");
                if ($columnCheck->num_rows > 0) {
                    $updateStmt = $profileConn->prepare("UPDATE user_profiles SET achievements = ? WHERE user_id = ?");
                    $updateStmt->bind_param("ss", $updatedAchievements, $_SESSION['user_id']);
                    if ($updateStmt->execute()) {
                        $sectionSuccess = true;
                        $sectionType = 'achievement';
                    }
                    $updateStmt->close();
                }
            }
        }
    }
    
    if (isset($_POST['add_volunteer'])) {
        $role = trim($_POST['volunteer_role'] ?? '');
        $organization = trim($_POST['volunteer_organization'] ?? '');
        $duration = trim($_POST['volunteer_duration'] ?? '');
        $description = trim($_POST['volunteer_description'] ?? '');
        
        if (!empty($role) && !empty($organization)) {
            $newVolunteer = "$role|$organization|$duration|$description";
            $currentVolunteer = !empty($userProfile['volunteer_work']) ? explode(';', $userProfile['volunteer_work']) : [];
            $currentVolunteer[] = $newVolunteer;
            $updatedVolunteer = implode(';', $currentVolunteer);
            
            // Check if column exists before updating
            $columnCheck = $profileConn->query("SHOW COLUMNS FROM user_profiles LIKE 'volunteer_work'");
            if ($columnCheck->num_rows > 0) {
                $updateStmt = $profileConn->prepare("UPDATE user_profiles SET volunteer_work = ? WHERE user_id = ?");
                $updateStmt->bind_param("ss", $updatedVolunteer, $_SESSION['user_id']);
                if ($updateStmt->execute()) {
                    $sectionSuccess = true;
                    $sectionType = 'volunteer';
                }
                $updateStmt->close();
            }
        }
    }
    
    if (isset($_POST['add_mentorship'])) {
        $newMentorship = trim($_POST['new_mentorship'] ?? '');
        if (!empty($newMentorship)) {
            $currentMentorship = !empty($userProfile['mentorship']) ? explode(',', $userProfile['mentorship']) : [];
            if (!in_array($newMentorship, $currentMentorship)) {
                $currentMentorship[] = $newMentorship;
                $updatedMentorship = implode(',', $currentMentorship);
                
                // Check if column exists before updating
                $columnCheck = $profileConn->query("SHOW COLUMNS FROM user_profiles LIKE 'mentorship'");
                if ($columnCheck->num_rows > 0) {
                    $updateStmt = $profileConn->prepare("UPDATE user_profiles SET mentorship = ? WHERE user_id = ?");
                    $updateStmt->bind_param("ss", $updatedMentorship, $_SESSION['user_id']);
                    if ($updateStmt->execute()) {
                        $sectionSuccess = true;
                        $sectionType = 'mentorship';
                    }
                    $updateStmt->close();
                }
            }
        }
    }
    
    if (isset($_POST['add_learning_goal'])) {
        $goal = trim($_POST['learning_goal'] ?? '');
        $description = trim($_POST['learning_description'] ?? '');
        $progress = intval($_POST['learning_progress'] ?? 0);
        
        if (!empty($goal)) {
            $newLearningGoal = "$goal|$description|$progress";
            $currentLearningGoals = !empty($userProfile['learning_goals']) ? explode(';', $userProfile['learning_goals']) : [];
            $currentLearningGoals[] = $newLearningGoal;
            $updatedLearningGoals = implode(';', $currentLearningGoals);
            
            // Check if column exists before updating
            $columnCheck = $profileConn->query("SHOW COLUMNS FROM user_profiles LIKE 'learning_goals'");
            if ($columnCheck->num_rows > 0) {
                $updateStmt = $profileConn->prepare("UPDATE user_profiles SET learning_goals = ? WHERE user_id = ?");
                $updateStmt->bind_param("ss", $updatedLearningGoals, $_SESSION['user_id']);
                if ($updateStmt->execute()) {
                    $sectionSuccess = true;
                    $sectionType = 'learning_goal';
                }
                $updateStmt->close();
            }
        }
    }
    
    // Refresh profile data after any update
    if ($sectionSuccess) {
        $profileStmt->execute();
        $profileResult = $profileStmt->get_result();
        $userProfile = $profileResult->fetch_assoc();
    }
}

// Handle share profile
if (isset($_POST['share_profile'])) {
    // Change this line from the production URL to your localhost URL
    $profileUrl = "http://localhost/WEBPAGES/CodeSphere/auth/profile.php?user=" . $user['username'] . "_" . $_SESSION['user_id'];
    $shareMessage = "Check out my CodeSphere profile: " . $profileUrl;
    $shareSuccess = '
        <div class="share-link-container">
            <div class="share-link-header">
                <ion-icon name="link-outline"></ion-icon>
                <span>Profile link ready to share!</span>
            </div>
            <div class="share-link-content">
                <div class="share-url-display">
                    <input type="text" class="share-url-input" id="shareUrlInput" value="' . $profileUrl . '" readonly>
                    <button class="copy-btn" onclick="copyProfileUrl()" title="Copy to clipboard">
                        <i class="fas fa-copy"></i>
                    </button>
                </div>
                <div class="share-actions">
                    <a href="' . $profileUrl . '" target="_blank" class="btn btn-sm btn-outline">
                        <ion-icon name="open-outline"></ion-icon>
                        Open Profile
                    </a>
                </div>
            </div>
        </div>
    ';
}

// Handle download profile as PDF
if (isset($_POST['download_pdf'])) {
    $pdfSuccess = "PDF download started!";
}

// Get user repositories from user_repos database
$reposConn = getDBConnection(DB_NAME_USER_REPOS);
$tableName = $user['username'] . '_' . $_SESSION['user_id'];
$userRepos = [];

// Check if the user's repository table exists
$tableCheck = $reposConn->query("SHOW TABLES LIKE '$tableName'");
if ($tableCheck->num_rows > 0) {
    $repoStmt = $reposConn->prepare("SELECT * FROM `$tableName` ORDER BY access_start DESC");
    $repoStmt->execute();
    $userRepos = $repoStmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Get real statistics
$totalRepos = count($userRepos);
$collaboratorRepos = 0;
$ownerRepos = 0;

foreach ($userRepos as $repo) {
    if ($repo['role'] === 'collaborator') {
        $collaboratorRepos++;
    } else {
        $ownerRepos++;
    }
}

// Get total commits across all repositories
$totalCommits = 0;
foreach ($userRepos as $repo) {
    $repoDb = $repo['repo_id'];
    $repoConn = new mysqli(DB_HOST, DB_USER, DB_PASS, $repoDb);
    if (!$repoConn->connect_error) {
        $commitStmt = $repoConn->prepare("SELECT COUNT(*) as commit_count FROM commits WHERE author_id = ?");
        $commitStmt->bind_param("s", $_SESSION['user_id']);
        $commitStmt->execute();
        $commitResult = $commitStmt->get_result()->fetch_assoc();
        $totalCommits += $commitResult['commit_count'] ?? 0;
        $repoConn->close();
    }
}

// Calculate real contribution streak (last 30 days)
$contributionStreak = 0;
$today = date('Y-m-d');
for ($i = 0; $i < 30; $i++) {
    $checkDate = date('Y-m-d', strtotime("-$i days"));
    $hasContribution = false;
    
    foreach ($userRepos as $repo) {
        $repoDb = $repo['repo_id'];
        $repoConn = new mysqli(DB_HOST, DB_USER, DB_PASS, $repoDb);
        if (!$repoConn->connect_error) {
            $streakStmt = $repoConn->prepare("SELECT COUNT(*) as count FROM commits WHERE author_id = ? AND DATE(timestamp) = ?");
            $streakStmt->bind_param("ss", $_SESSION['user_id'], $checkDate);
            $streakStmt->execute();
            $streakResult = $streakStmt->get_result()->fetch_assoc();
            if ($streakResult['count'] > 0) {
                $hasContribution = true;
                break;
            }
            $repoConn->close();
        }
    }
    
    if ($hasContribution) {
        $contributionStreak++;
    } else {
        break;
    }
}

// Get notifications
$notifications = [];
$unreadNotificationCount = 0;

// Check if notifications database and table exist
$notifConn = new mysqli(DB_HOST, DB_USER, DB_PASS, 'notifications');
if (!$notifConn->connect_error) {
    // Use just the user ID as table name
    $notifTableName = $_SESSION['user_id'];
    $notifTableCheck = $notifConn->query("SHOW TABLES LIKE '$notifTableName'");
    
    if ($notifTableCheck->num_rows > 0) {
        $notifStmt = $notifConn->prepare("SELECT * FROM `$notifTableName` ORDER BY created_at DESC LIMIT 50");
        $notifStmt->execute();
        $notifications = $notifStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        // Count unread notifications
        $unreadStmt = $notifConn->prepare("SELECT COUNT(*) as count FROM `$notifTableName` WHERE is_read = 0");
        $unreadStmt->execute();
        $unreadResult = $unreadStmt->get_result()->fetch_assoc();
        $unreadNotificationCount = $unreadResult['count'];
    }
}

// Calculate statistics based on available data
$stats = [
    'total_repos' => $totalRepos,
    'owner_repos' => $ownerRepos,
    'collaborator_repos' => $collaboratorRepos,
    'total_commits' => $totalCommits,
    'collaborators_count' => $collaboratorRepos,
];

// Get recent 5 repositories
$recentRepos = array_slice($userRepos, 0, 5);

// Close connections
$conn->close();
$reposConn->close();
$profileConn->close();
if (isset($notifConn)) $notifConn->close();

// Parse skill levels if they exist
$skillLevels = [];
if (!empty($userProfile['skill_levels'])) {
    $skillLevels = json_decode($userProfile['skill_levels'], true) ?: [];
}

// Parse language levels if they exist
$languageLevels = [];
if (!empty($userProfile['language_levels'])) {
    $languageLevels = json_decode($userProfile['language_levels'], true) ?: [];
}

// Parse arrays for display - safely handle missing columns
$skillsArray = !empty($userProfile['skills']) ? explode(',', $userProfile['skills']) : [];
$interestsArray = !empty($userProfile['interests']) ? explode(',', $userProfile['interests']) : [];
$hobbiesArray = !empty($userProfile['hobbies']) ? explode(',', $userProfile['hobbies']) : [];
$languagesArray = !empty($userProfile['languages']) ? explode(',', $userProfile['languages']) : [];
$certificationsArray = !empty($userProfile['certifications']) ? explode(',', $userProfile['certifications']) : [];
$achievementsArray = !empty($userProfile['achievements']) ? explode(',', $userProfile['achievements']) : [];
$mentorshipArray = !empty($userProfile['mentorship']) ? explode(',', $userProfile['mentorship']) : [];

// Parse complex data - safely handle missing columns
$educationArray = !empty($userProfile['education']) ? explode(';', $userProfile['education']) : [];
$experienceArray = !empty($userProfile['work_experience']) ? explode(';', $userProfile['work_experience']) : [];
$projectsArray = !empty($userProfile['projects']) ? explode(';', $userProfile['projects']) : [];
$publicationsArray = !empty($userProfile['publications']) ? explode(';', $userProfile['publications']) : [];
$volunteerArray = !empty($userProfile['volunteer_work']) ? explode(';', $userProfile['volunteer_work']) : [];
$learningGoalsArray = !empty($userProfile['learning_goals']) ? explode(';', $userProfile['learning_goals']) : [];
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $_SESSION['theme']; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - CodeSphere</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
    /* Prevent horizontal scroll */
    html, body { 
        overflow-x: hidden; 
        max-width: 100%; 
    }
    html, body {
    overflow: auto;
    scrollbar-width: none; /* Firefox */
    -ms-overflow-style: none; /* IE and Edge */
}

/* Webkit browsers (Chrome, Safari, Opera) */
html::-webkit-scrollbar,
body::-webkit-scrollbar,
.main-content::-webkit-scrollbar,
.profile::-webkit-scrollbar,
.sidebar-nav::-webkit-scrollbar,
.modal-body::-webkit-scrollbar {
    display: none;
    width: 0;
    height: 0;
}

/* Ensure proper sizing */
body {
    margin: 0;
    padding: 0;
}

.main-content {
    height: 100vh;
    overflow: auto;
    scrollbar-width: none;
    -ms-overflow-style: none;
}

.main-content::-webkit-scrollbar {
    display: none;
}
</style>
    <style>
        /* Enhanced CSS with fixes for all identified issues */
        /* Prevent horizontal scrolling throughout the entire page */
html, body {
    overflow-x: hidden;
    max-width: 100%;
    width: 100%;
}

body {
    position: relative;
}

/* Ensure main containers don't cause overflow */
.main-content {
    overflow-x: hidden;
    width: 100%;
}

.profile {
    overflow-x: hidden;
    width: 100%;
}

/* Fix for profile grid causing overflow */
.profile-grid {
    width: 100%;
    box-sizing: border-box;
}

/* Ensure cards don't exceed container width */
.profile-card {
    max-width: 100%;
    box-sizing: border-box;
}

/* Fix for tabs causing overflow */
.profile-tabs {
    width: 100%;
    box-sizing: border-box;
}

/* Fix for modals */
.modal {
    max-width: 95vw;
    box-sizing: border-box;
}

/* Responsive fixes */
@media (max-width: 768px) {
    .profile {
        padding: 1rem;
        box-sizing: border-box;
    }
    
    .profile-header {
        margin-left: 0;
        margin-right: 0;
    }
}
        :root {
            --primary: #6366f1;
            --primary-dark: #4f46e5;
            --primary-light: #c7d2fe;
            --secondary: #8b5cf6;
            --accent: #f59e0b;
            --success: #10b981;
            --warning: #f59e0b;
            --error: #ef4444;
            --dark: #0f172a;
            --darker: #020617;
            --light: #f8fafc;
            --gray: #64748b;
            --light-gray: #e2e8f0;
            --sidebar-bg: #ffffff;
            --sidebar-hover: #f1f5f9;
            --card-bg: #ffffff;
            --border: #e2e8f0;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            --shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 25px rgba(0, 0, 0, 0.1);
            --shadow-xl: 0 20px 40px rgba(0, 0, 0, 0.15);
            --radius: 8px;
            --radius-lg: 12px;
            --radius-xl: 16px;
            --gradient: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            --gradient-light: linear-gradient(135deg, rgba(99, 102, 241, 0.1) 0%, rgba(139, 92, 246, 0.1) 100%);
        }

        .dark-mode {
            --primary: #818cf8;
            --primary-dark: #6366f1;
            --primary-light: #3730a3;
            --secondary: #a78bfa;
            --accent: #fbbf24;
            --dark: #f1f5f9;
            --darker: #0f172a;
            --light: #0f172a;
            --gray: #94a3b8;
            --light-gray: #1e293b;
            --sidebar-bg: #1e293b;
            --sidebar-hover: #334155;
            --card-bg: #1e293b;
            --border: #334155;
            --shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
            --shadow-lg: 0 10px 25px rgba(0, 0, 0, 0.3);
            --shadow-xl: 0 20px 40px rgba(0, 0, 0, 0.4);
            --gradient-light: linear-gradient(135deg, rgba(129, 140, 248, 0.1) 0%, rgba(167, 139, 250, 0.1) 100%);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--light);
            color: var(--dark);
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            transition: var(--transition);
            overflow-x: hidden;
        }

        /* Enhanced Sidebar - Fixed visibility issues */
        .sidebar {
            width: 280px;
            background: var(--sidebar-bg);
            border-right: 1px solid var(--border);
            height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
            transition: var(--transition);
            z-index: 1000;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: var(--shadow-lg);
        }

        .sidebar.collapsed {
            width: 72px;
        }

        .sidebar-header {
            padding: 1.5rem 1.25rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex-shrink: 0;
            background: var(--gradient);
            color: white;
            position: relative;
            overflow: hidden;
        }

        .sidebar-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 120px;
            height: 120px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
        }

        .sidebar.collapsed .sidebar-header {
            justify-content: center;
            padding: 1.5rem 0.75rem;
        }

        .logo {
            display: flex;
            align-items: center;
            font-size: 1.25rem;
            font-weight: 700;
            color: white;
            position: relative;
            z-index: 1;
        }

        .logo-icon {
            width: 32px;
            height: 32px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1rem;
            backdrop-filter: blur(10px);
        }

        .logo-text {
            margin-left: 0.75rem;
            font-weight: 700;
            transition: opacity 0.3s ease;
        }

        .sidebar.collapsed .logo-text {
            display: none;
        }

        .sidebar-toggle {
            background: none;
            border: none;
            color: white;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: var(--radius);
            transition: var(--transition);
            margin-left: auto;
            position: relative;
            z-index: 1;
        }

        .sidebar-toggle:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: rotate(180deg);
        }

        .sidebar.collapsed .sidebar-toggle {
            margin-left: 0;
            transform: rotate(180deg);
        }

        .sidebar-nav {
            flex: 1;
            overflow-y: auto;
            padding: 1rem 0;
            scrollbar-width: none;
            -ms-overflow-style: none;
        }

        .sidebar-nav::-webkit-scrollbar {
            display: none;
        }

        .nav-section {
            margin-bottom: 1.5rem;
        }

        .nav-title {
            padding: 0.5rem 1.25rem;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            transition: var(--transition);
            white-space: nowrap;
            overflow: hidden;
        }

        .sidebar.collapsed .nav-title {
            display: none;
        }

        .nav-item {
            display: flex;
            align-items: center;
            padding: 0.75rem 1.25rem;
            color: var(--dark);
            transition: var(--transition);
            border-left: 3px solid transparent;
            margin: 0.125rem 0;
            position: relative;
            overflow: hidden;
            white-space: nowrap;
        }

        .nav-item::before {
            content: '';
            position: absolute;
            left: -100%;
            top: 0;
            width: 100%;
            height: 100%;
            background: var(--gradient);
            transition: var(--transition);
            opacity: 0.1;
        }

        .nav-item:hover {
            background: var(--sidebar-hover);
            color: var(--primary);
            transform: translateX(4px);
        }

        .nav-item:hover::before {
            left: 0;
        }

        .nav-item.active {
            background: var(--sidebar-hover);
            color: var(--primary);
            border-left-color: var(--primary);
            font-weight: 500;
        }

        .nav-item.active::before {
            left: 0;
        }

        .nav-icon {
            width: 20px;
            text-align: center;
            font-size: 1.1rem;
            position: relative;
            z-index: 1;
            transition: var(--transition);
            flex-shrink: 0;
        }

        .nav-item:hover .nav-icon {
            transform: scale(1.1);
        }

        .nav-text {
            margin-left: 0.75rem;
            font-size: 0.9rem;
            font-weight: 500;
            position: relative;
            z-index: 1;
            transition: opacity 0.3s ease;
            white-space: nowrap;
            overflow: hidden;
        }

        .sidebar.collapsed .nav-text {
            display: none;
        }

        .sidebar.collapsed .nav-item {
            justify-content: center;
            padding: 0.75rem;
        }

        .nav-badge {
            margin-left: auto;
            background: var(--primary);
            color: white;
            padding: 0.2rem 0.5rem;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            min-width: 20px;
            text-align: center;
            position: relative;
            z-index: 1;
            animation: pulse 2s infinite;
            transition: opacity 0.3s ease;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        .sidebar.collapsed .nav-badge {
            display: none;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            transition: var(--transition);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .main-content.expanded {
            margin-left: 72px;
        }

        /* Header */
        header {
            background: var(--card-bg);
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            z-index: 999;
            backdrop-filter: blur(8px);
            box-shadow: var(--shadow);
        }

        .top-nav {
            display: flex;
            align-items: center;
            padding: 1rem 2rem;
            gap: 1.5rem;
        }

        .mobile-menu-btn {
            display: none;
            background: none;
            border: none;
            color: var(--dark);
            font-size: 1.2rem;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: var(--radius);
            transition: var(--transition);
        }

        .mobile-menu-btn:hover {
            background: var(--sidebar-hover);
            transform: scale(1.1);
        }

        .search-bar {
            flex: 1;
            max-width: 500px;
        }

        .search-container {
            position: relative;
        }

        .search-input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 2.5rem;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            background: var(--light);
            color: var(--dark);
            font-size: 0.9rem;
            transition: var(--transition);
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
            transform: translateY(-1px);
        }

        .search-icon {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
        }

        .nav-right {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .nav-btn {
            background: none;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: var(--radius);
            color: var(--dark);
            font-size: 1.1rem;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }

        .nav-btn:hover {
            background: var(--sidebar-hover);
            transform: translateY(-2px);
        }

        .notification-badge {
            position: absolute;
            top: -2px;
            right: -2px;
            background: var(--error);
            color: white;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            animation: pulse 1.5s infinite;
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.5rem;
            border-radius: var(--radius);
            cursor: pointer;
            transition: var(--transition);
        }

        .user-menu:hover {
            background: var(--sidebar-hover);
            transform: translateY(-2px);
        }

        .user-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: var(--gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.9rem;
            transition: var(--transition);
        }

        .user-menu:hover .user-avatar {
            transform: scale(1.1);
        }

        .user-info {
            display: flex;
            flex-direction: column;
        }

        .user-name {
            font-weight: 600;
            font-size: 0.9rem;
        }

        .user-role {
            font-size: 0.8rem;
            color: var(--gray);
        }

        /* Profile Content */
        .profile {
            flex: 1;
            padding: 2rem;
        }

        .profile-header {
            background: <?php echo $userProfile['avatar_color'] ?? 'var(--gradient)'; ?>;
            color: white;
            padding: 3rem 2.5rem;
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-lg);
            position: relative;
            overflow: hidden;
            margin-bottom: 2.5rem;
            animation: slideInUp 0.8s ease-out;
        }

        .profile-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 300px;
            height: 300px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            animation: float 15s infinite ease-in-out;
        }

        .profile-header::after {
            content: '';
            position: absolute;
            bottom: -30%;
            left: -10%;
            width: 200px;
            height: 200px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 50%;
            animation: float 12s infinite ease-in-out reverse;
        }

        .profile-content {
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            gap: 2rem;
        }

        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2.5rem;
            font-weight: 700;
            backdrop-filter: blur(10px);
            border: 3px solid rgba(255, 255, 255, 0.3);
            transition: var(--transition);
            animation: bounce 2s infinite;
        }

        .profile-avatar:hover {
            transform: scale(1.05);
            border-color: rgba(255, 255, 255, 0.5);
        }

        .profile-info {
            flex: 1;
        }

        .profile-name {
            font-size: 2.2rem;
            margin-bottom: 0.5rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .profile-username {
            font-size: 1.2rem;
            opacity: 0.9;
            font-weight: 400;
        }

        .profile-bio {
            font-size: 1.1rem;
            margin-bottom: 1rem;
            max-width: 600px;
            opacity: 0.9;
        }

        .profile-meta {
            display: flex;
            gap: 1.5rem;
            font-size: 0.9rem;
            opacity: 0.8;
            flex-wrap: wrap;
        }

        .profile-meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .profile-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
            flex-wrap: wrap;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--radius);
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            position: relative;
            overflow: hidden;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: var(--transition);
        }

        .btn:hover::before {
            left: 100%;
        }

        .btn-primary {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .btn-primary:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .btn-secondary {
            background: transparent;
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: translateY(-2px);
        }

        /* Fixed Profile Grid - Cards now properly sized */
        .profile-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .profile-card {
            background: var(--card-bg);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            overflow: hidden;
            box-shadow: var(--shadow);
            transition: var(--transition);
            animation: fadeInUp 0.6s ease-out;
            display: flex;
            flex-direction: column;
            min-height: 300px;
        }

        .profile-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }

        .card-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .card-header h2 {
            font-size: 1.3rem;
            color: var(--dark);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .card-icon {
            color: var(--primary);
            font-size: 1.1rem;
        }

        .card-body {
            padding: 1.5rem;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-item {
            text-align: center;
            padding: 1.5rem 1rem;
            background: var(--gradient-light);
            border-radius: var(--radius);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .stat-item::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: var(--gradient);
            transition: var(--transition);
            opacity: 0.1;
        }

        .stat-item:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow);
        }

        .stat-item:hover::before {
            left: 0;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 0.5rem;
            position: relative;
            z-index: 1;
        }

        .stat-label {
            font-size: 0.9rem;
            color: var(--gray);
            position: relative;
            z-index: 1;
        }

        .info-list {
            list-style: none;
        }

        .info-item {
            display: flex;
            align-items: center;
            padding: 1rem 0;
            border-bottom: 1px solid var(--border);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .info-item::before {
            content: '';
            position: absolute;
            left: -100%;
            top: 0;
            width: 100%;
            height: 100%;
            background: var(--gradient-light);
            transition: var(--transition);
        }

        .info-item:hover {
            transform: translateX(4px);
        }

        .info-item:hover::before {
            left: 0;
        }

        .info-item:last-child {
            border-bottom: none;
        }

        .info-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: var(--primary-light);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            margin-right: 1rem;
            flex-shrink: 0;
            position: relative;
            z-index: 1;
            transition: var(--transition);
        }

        .info-item:hover .info-icon {
            transform: scale(1.1);
            background: var(--primary);
            color: white;
        }

        .info-content {
            flex: 1;
            position: relative;
            z-index: 1;
        }

        .info-label {
            font-size: 0.9rem;
            color: var(--gray);
            margin-bottom: 0.25rem;
        }

        .info-value {
            font-weight: 500;
            color: var(--dark);
        }

        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
            color: var(--gray);
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
            animation: bounce 2s infinite;
        }

        .empty-state h3 {
            font-size: 1.2rem;
            margin-bottom: 0.5rem;
            color: var(--dark);
        }

        /* Enhanced Form Styling */
        .form-group {
            margin-bottom: 2rem;
            position: relative;
        }

        .form-label {
            display: block;
            margin-bottom: 0.75rem;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.95rem;
            transition: var(--transition);
        }

        .form-input, .form-textarea, .form-select {
            width: 100%;
            padding: 1rem 1.25rem;
            border: 2px solid var(--border);
            border-radius: var(--radius-lg);
            background: var(--card-bg);
            color: var(--dark);
            font-size: 1rem;
            transition: var(--transition);
            font-family: 'Inter', sans-serif;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }

        .form-input:focus, .form-textarea:focus, .form-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
            transform: translateY(-2px);
        }

        .form-input:hover, .form-textarea:hover, .form-select:hover {
            border-color: var(--primary-light);
        }

        .form-textarea {
            resize: vertical;
            min-height: 120px;
            line-height: 1.5;
        }

        .form-help {
            font-size: 0.85rem;
            color: var(--gray);
            margin-top: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Modern Checkbox */
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            background: var(--gradient-light);
            border-radius: var(--radius-lg);
            border: 2px solid transparent;
            transition: var(--transition);
            cursor: pointer;
        }

        .checkbox-group:hover {
            border-color: var(--primary-light);
            transform: translateY(-2px);
        }

        .checkbox {
            width: 20px;
            height: 20px;
            border-radius: 6px;
            border: 2px solid var(--border);
            background: var(--card-bg);
            cursor: pointer;
            transition: var(--transition);
            position: relative;
        }

        .checkbox:checked {
            background: var(--primary);
            border-color: var(--primary);
        }

        .checkbox:checked::before {
            content: '✓';
            position: absolute;
            color: white;
            font-size: 0.8rem;
            font-weight: bold;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
        }

        /* Modern Color Picker */
        .color-picker {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(50px, 1fr));
            gap: 0.75rem;
            margin-top: 0.5rem;
        }

        .color-option {
            width: 50px;
            height: 50px;
            border-radius: var(--radius-lg);
            cursor: pointer;
            border: 3px solid transparent;
            transition: var(--transition);
            position: relative;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .color-option:hover {
            transform: scale(1.1) rotate(5deg);
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.15);
        }

        .color-option.selected {
            border-color: var(--dark);
            transform: scale(1.15);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2);
        }

        .color-option.selected::after {
            content: '✓';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: white;
            font-weight: bold;
            font-size: 1rem;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }

        /* Modern Social Links Grid */
        .social-links {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-top: 1rem;
        }

        .social-link-group {
            position: relative;
        }

        .social-link-group .form-input {
            padding-left: 3rem;
        }

        .social-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
            font-size: 1.1rem;
            transition: var(--transition);
        }

        .form-input:focus + .social-icon {
            color: var(--primary);
        }

        /* Form Sections */
        .form-section {
            background: var(--gradient-light);
            padding: 1.5rem;
            border-radius: var(--radius-lg);
            margin-bottom: 2rem;
            border-left: 4px solid var(--primary);
        }

        .form-section-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        /* Floating Labels Effect */
        .floating-label-group {
            position: relative;
            margin-bottom: 2rem;
        }

        .floating-label {
            position: absolute;
            left: 1.25rem;
            top: 1rem;
            background: var(--card-bg);
            padding: 0 0.5rem;
            color: var(--gray);
            font-size: 0.9rem;
            transition: var(--transition);
            pointer-events: none;
        }

        .form-input:focus ~ .floating-label,
        .form-input:not(:placeholder-shown) ~ .floating-label {
            top: -0.5rem;
            font-size: 0.8rem;
            color: var(--primary);
            font-weight: 600;
        }

        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            opacity: 0;
            visibility: hidden;
            transition: var(--transition);
            backdrop-filter: blur(4px);
        }

        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .modal {
            background: var(--card-bg);
            border-radius: var(--radius-xl);
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-xl);
            transform: translateY(20px) scale(0.95);
            transition: var(--transition);
        }

        .modal-overlay.active .modal {
            transform: translateY(0) scale(1);
        }

        .modal-header {
            padding: 1.5rem 2rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--gradient);
            color: white;
        }

        .modal-header h3 {
            font-size: 1.3rem;
            font-weight: 600;
        }

        .modal-close {
            background: none;
            border: none;
            color: white;
            cursor: pointer;
            font-size: 1.2rem;
            padding: 0.5rem;
            border-radius: var(--radius);
            transition: var(--transition);
        }

        .modal-close:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: rotate(90deg);
        }

        .modal-body {
            padding: 2rem;
            max-height: calc(70vh - 120px);
            overflow-y: auto;
            scrollbar-width: thin;
            scrollbar-color: var(--primary) transparent;
        }

        .modal-body::-webkit-scrollbar {
            width: 6px;
        }

        .modal-body::-webkit-scrollbar-track {
            background: transparent;
        }

        .modal-body::-webkit-scrollbar-thumb {
            background-color: var(--primary);
            border-radius: 3px;
        }

        .modal-body::-webkit-scrollbar-thumb:hover {
            background-color: var(--primary-dark);
        }

        .modal {
            max-height: 90vh;
            overflow: hidden;
        }

        .modal-footer {
            padding: 1.5rem 2rem;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            background: #0da271;
            transform: translateY(-2px);
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--border);
            color: var(--dark);
        }

        .btn-outline:hover {
            background: var(--sidebar-hover);
            transform: translateY(-2px);
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
        }

        .alert {
            padding: 1rem 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            border-left: 4px solid;
            animation: slideInRight 0.5s ease-out;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            border-color: var(--success);
            color: #0f5132;
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border-color: var(--error);
            color: #721c24;
        }

        .dark-mode .alert-success {
            background: rgba(16, 185, 129, 0.2);
            color: #d1fae5;
        }

        .dark-mode .alert-error {
            background: rgba(239, 68, 68, 0.2);
            color: #fecaca;
        }

        /* NEW STYLES FOR ENHANCED FEATURES */

        /* Enhanced tag styles */
        .tags {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }
        
        .tag {
            background: var(--primary-light);
            color: var(--primary);
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .tag:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
            background: var(--primary);
            color: white;
        }
        
        .tag i {
            font-size: 0.8rem;
        }
        
        /* Section-specific tag colors */
        .skills-tag {
            background: rgba(99, 102, 241, 0.1);
            color: var(--primary);
        }
        
        .interests-tag {
            background: rgba(139, 92, 246, 0.1);
            color: var(--secondary);
        }
        
        .hobbies-tag {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .languages-tag {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }
        
        .education-tag {
            background: rgba(236, 72, 153, 0.1);
            color: #ec4899;
        }
        
        .certifications-tag {
            background: rgba(6, 182, 212, 0.1);
            color: #06b6d4;
        }
        
        .projects-tag {
            background: rgba(132, 204, 22, 0.1);
            color: #84cc16;
        }
        
        .achievements-tag {
            background: rgba(239, 68, 68, 0.1);
            color: var(--error);
        }
        
        .publications-tag {
            background: rgba(168, 85, 247, 0.1);
            color: #a855f7;
        }
        
        .volunteer-tag {
            background: rgba(14, 165, 233, 0.1);
            color: #0ea5e9;
        }
        
        .mentorship-tag {
            background: rgba(251, 191, 36, 0.1);
            color: #fbbf24;
        }

        /* Progress bars for skills */
        .skill-item {
            margin-bottom: 1.5rem;
        }
        
        .skill-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
        }
        
        .skill-name {
            font-weight: 600;
            color: var(--dark);
        }
        
        .skill-level {
            color: var(--gray);
            font-size: 0.9rem;
        }
        
        .skill-bar {
            height: 8px;
            background: var(--light-gray);
            border-radius: 4px;
            overflow: hidden;
        }
        
        .skill-progress {
            height: 100%;
            background: var(--gradient);
            border-radius: 4px;
            transition: width 1s ease-in-out;
        }
        
        /* Timeline for education and experience */
        .timeline {
            position: relative;
            padding-left: 2rem;
        }
        
        .timeline::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 2px;
            background: var(--primary-light);
        }
        
        .timeline-item {
            position: relative;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
        }
        
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -2.25rem;
            top: 0.5rem;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: var(--primary);
            border: 2px solid white;
            box-shadow: 0 0 0 3px var(--primary-light);
        }
        
        .timeline-date {
            font-size: 0.85rem;
            color: var(--gray);
            margin-bottom: 0.5rem;
        }
        
        .timeline-content {
            background: var(--card-bg);
            padding: 1rem;
            border-radius: var(--radius);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
        }
        
        .timeline-title {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--dark);
        }
        
        .timeline-description {
            font-size: 0.9rem;
            color: var(--gray);
        }
        
        /* Badges for achievements */
        .badges-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 1rem;
        }
        
        .badge {
            text-align: center;
            padding: 1.5rem 1rem;
            background: var(--gradient-light);
            border-radius: var(--radius-lg);
            transition: var(--transition);
            border: 2px solid transparent;
        }
        
        .badge:hover {
            transform: translateY(-5px);
            border-color: var(--primary);
            box-shadow: var(--shadow-lg);
        }
        
        .badge-icon {
            font-size: 2rem;
            margin-bottom: 0.75rem;
            color: var(--primary);
        }
        
        .badge-title {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--dark);
        }
        
        .badge-description {
            font-size: 0.8rem;
            color: var(--gray);
        }
        
        /* Project cards */
        .projects-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
        }
        
        .project-card {
            background: var(--card-bg);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            overflow: hidden;
            transition: var(--transition);
            box-shadow: var(--shadow);
        }
        
        .project-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }
        
        .project-header {
            padding: 1.25rem;
            border-bottom: 1px solid var(--border);
        }
        
        .project-title {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--dark);
        }
        
        .project-description {
            font-size: 0.9rem;
            color: var(--gray);
        }
        
        .project-body {
            padding: 1.25rem;
        }
        
        .project-tech {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        
        .project-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--primary);
            font-weight: 500;
            transition: var(--transition);
        }
        
        .project-link:hover {
            color: var(--primary-dark);
            transform: translateX(4px);
        }
        
        /* Tabs for profile sections */
        .profile-tabs {
            display: flex;
            border-bottom: 1px solid var(--border);
            margin-bottom: 2rem;
            overflow-x: auto;
            scrollbar-width: none;
        }
        
        .profile-tabs::-webkit-scrollbar {
            display: none;
        }
        
        .profile-tab {
            padding: 1rem 1.5rem;
            background: none;
            border: none;
            color: var(--gray);
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            white-space: nowrap;
            border-bottom: 2px solid transparent;
        }
        
        .profile-tab:hover {
            color: var(--primary);
        }
        
        .profile-tab.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
            animation: fadeIn 0.5s ease-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        /* Enhanced modal for editing */
        .modal-tabs {
            display: flex;
            border-bottom: 1px solid var(--border);
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }
        
        .modal-tab {
            padding: 0.75rem 1.5rem;
            background: none;
            border: none;
            color: var(--gray);
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            border-bottom: 2px solid transparent;
            flex: 1;
            min-width: 120px;
        }
        
        .modal-tab:hover {
            color: var(--primary);
        }
        
        .modal-tab.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }
        
        .modal-tab-content {
            display: none;
        }
        
        .modal-tab-content.active {
            display: block;
        }
        
        /* Skill level selector */
        .skill-level-selector {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }
        
        .level-option {
            flex: 1;
            text-align: center;
            padding: 0.5rem;
            background: var(--light-gray);
            border-radius: var(--radius);
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.8rem;
        }
        
        .level-option:hover {
            background: var(--primary-light);
            color: var(--primary);
        }
        
        .level-option.selected {
            background: var(--primary);
            color: white;
        }
        
        /* Availability Status */
        .availability-status {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        .status-available {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .status-busy {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }
        
        .status-away {
            background: rgba(99, 102, 241, 0.1);
            color: var(--primary);
        }
        
        .status-offline {
            background: rgba(100, 116, 139, 0.1);
            color: var(--gray);
        }
        
        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }
        
        .status-available .status-dot {
            background: var(--success);
        }
        
        .status-busy .status-dot {
            background: var(--warning);
        }
        
        .status-away .status-dot {
            background: var(--primary);
        }
        
        .status-offline .status-dot {
            background: var(--gray);
        }
        
        /* Hourly Rate */
        .hourly-rate {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
        }
        
        /* Learning Goals Progress */
        .learning-goals {
            margin-top: 1rem;
        }
        
        .goal-item {
            margin-bottom: 1.5rem;
        }
        
        .goal-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
        }
        
        .goal-title {
            font-weight: 600;
            color: var(--dark);
        }
        
        .goal-progress {
            font-size: 0.9rem;
            color: var(--gray);
        }
        
        .goal-bar {
            height: 6px;
            background: var(--light-gray);
            border-radius: 3px;
            overflow: hidden;
        }
        
        .goal-progress-bar {
            height: 100%;
            background: var(--gradient);
            border-radius: 3px;
            transition: width 1s ease-in-out;
        }
        
        /* Publication List */
        .publication-list {
            list-style: none;
        }
        
        .publication-item {
            padding: 1rem 0;
            border-bottom: 1px solid var(--border);
        }
        
        .publication-item:last-child {
            border-bottom: none;
        }
        
        .publication-title {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--dark);
        }
        
        .publication-meta {
            font-size: 0.85rem;
            color: var(--gray);
            margin-bottom: 0.5rem;
        }
        
        .publication-link {
            color: var(--primary);
            text-decoration: none;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .publication-link:hover {
            color: var(--primary-dark);
        }
        
        /* Volunteer Work */
        .volunteer-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 1.5rem;
        }
        
        .volunteer-card {
            background: var(--gradient-light);
            padding: 1.5rem;
            border-radius: var(--radius-lg);
            border-left: 4px solid var(--primary);
        }
        
        .volunteer-role {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--dark);
        }
        
        .volunteer-organization {
            font-size: 0.9rem;
            color: var(--gray);
            margin-bottom: 0.5rem;
        }
        
        .volunteer-duration {
            font-size: 0.8rem;
            color: var(--gray);
        }
        
        /* Mentorship */
        .mentorship-areas {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        
        /* Enhanced Form Components */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }
        
        .form-card {
            background: var(--gradient-light);
            padding: 1.5rem;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            margin-bottom: 1.5rem;
        }
        
        .form-card-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .input-with-icon {
            position: relative;
        }
        
        .input-with-icon .form-input {
            padding-left: 3rem;
        }
        
        .input-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
        }
        
        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border);
        }
        
        /* Section Add Button */
        .section-add-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: var(--radius);
            font-size: 0.85rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            margin-top: 1rem;
        }
        
        .section-add-btn:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }
        
        /* Toast Notifications */
        .toast {
            position: fixed;
            top: 100px;
            right: 2rem;
            padding: 1rem 1.5rem;
            background: var(--success);
            color: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow-lg);
            z-index: 3000;
            transform: translateX(400px);
            transition: transform 0.3s ease-out;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            max-width: 350px;
        }
        
        .toast.show {
            transform: translateX(0);
        }
        
        .toast-error {
            background: var(--error);
        }
        
        .toast-warning {
            background: var(--warning);
        }
        
        .toast-info {
            background: var(--primary);
        }
        
        /* Share Profile Styles - Fixed sidebar extension issue */
        .share-link-container {
            background: var(--gradient-light);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            margin: 1rem 0;
            position: relative;
            z-index: 1;
        }
        
        .share-link-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1rem;
            color: var(--primary);
            font-weight: 600;
        }
        
        .share-link-header i {
            font-size: 1.2rem;
        }
        
        .share-url-display {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        
        .share-url-input {
            flex: 1;
            padding: 0.75rem 1rem;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            background: var(--card-bg);
            color: var(--dark);
            font-size: 0.9rem;
            font-family: monospace;
        }
        
        .copy-btn {
            padding: 0.75rem 1rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: var(--radius);
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .copy-btn:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }
        
        .copy-btn.copied {
            background: var(--success);
        }
        
        .copy-btn.copied i::before {
            content: "\\f00c";
        }
        
        .share-actions {
            display: flex;
            gap: 0.75rem;
            justify-content: flex-start;
        }
        
        /* Enhanced responsive design */
        @media (max-width: 1024px) {
            .sidebar {
                width: 72px;
            }

            .sidebar .nav-text,
            .sidebar .logo-text,
            .sidebar .nav-title,
            .sidebar .nav-badge {
                display: none;
            }

            .main-content {
                margin-left: 72px;
            }

            .profile-grid {
                grid-template-columns: 1fr;
            }

            .social-links {
                grid-template-columns: 1fr;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.mobile-open {
                transform: translateX(0);
                width: 280px;
            }

            .main-content {
                margin-left: 0;
            }

            .top-nav {
                padding: 1rem;
            }

            .search-bar {
                display: none;
            }

            .user-info {
                display: none;
            }

            .mobile-menu-btn {
                display: block;
            }

            .profile {
                padding: 1rem;
            }

            .profile-header {
                padding: 2rem 1.5rem;
            }

            .profile-content {
                flex-direction: column;
                text-align: center;
                gap: 1.5rem;
            }

            .profile-name {
                font-size: 1.8rem;
                justify-content: center;
            }

            .profile-meta {
                justify-content: center;
            }

            .profile-actions {
                justify-content: center;
            }

            .modal {
                width: 95%;
                margin: 1rem;
            }
            
            .profile-tabs {
                flex-wrap: nowrap;
                overflow-x: auto;
            }
            
            .profile-tab {
                padding: 0.75rem 1rem;
                font-size: 0.9rem;
            }
            
            .badges-container {
                grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            }
            
            .projects-grid {
                grid-template-columns: 1fr;
            }
            
            .volunteer-grid {
                grid-template-columns: 1fr;
            }
            
            .modal-tabs {
                flex-wrap: wrap;
            }
            
            .modal-tab {
                flex: 1;
                min-width: 120px;
            }
            
            .toast {
                right: 1rem;
                left: 1rem;
                max-width: none;
            }
        }

        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes bounce {
            0%, 20%, 50%, 80%, 100% {
                transform: translateY(0);
            }
            40% {
                transform: translateY(-10px);
            }
            60% {
                transform: translateY(-5px);
            }
        }

        @keyframes float {
            0%, 100% {
                transform: translateY(0) rotate(0deg);
            }
            33% {
                transform: translateY(-20px) rotate(120deg);
            }
            66% {
                transform: translateY(20px) rotate(240deg);
            }
        }

        @keyframes glow {
            0%, 100% {
                box-shadow: 0 0 5px rgba(99, 102, 241, 0.5);
            }
            50% {
                box-shadow: 0 0 20px rgba(99, 102, 241, 0.8);
            }
        }

        /* Floating elements */
        .floating-shapes {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            z-index: -1;
        }

        .shape {
            position: absolute;
            opacity: 0.03;
            border-radius: 50%;
            animation: float 25s infinite linear;
        }

        .shape-1 {
            width: 150px;
            height: 150px;
            background: var(--primary);
            top: 10%;
            left: 5%;
            animation-delay: 0s;
        }

        .shape-2 {
            width: 100px;
            height: 100px;
            background: var(--secondary);
            top: 70%;
            left: 80%;
            animation-delay: -8s;
        }

        .shape-3 {
            width: 200px;
            height: 200px;
            background: var(--accent);
            top: 40%;
            left: 70%;
            animation-delay: -16s;
        }

        .shape-4 {
            width: 80px;
            height: 80px;
            background: var(--success);
            top: 20%;
            left: 85%;
            animation-delay: -12s;
        }

        .shape-5 {
            width: 120px;
            height: 120px;
            background: var(--error);
            top: 80%;
            left: 10%;
            animation-delay: -4s;
        }

        /* Enhanced animations for cards */
        .profile-card {
            animation: fadeInUp 0.6s ease-out;
        }

        .stat-item {
            animation: fadeInUp 0.6s ease-out;
        }

        /* Glow effect for important elements */
        .profile-avatar {
            animation: bounce 2s infinite, glow 3s infinite;
        }

        .nav-badge {
            animation: pulse 2s infinite, glow 3s infinite;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }
        .shake {
            animation: shake 0.5s ease-in-out;
        }
    </style>
</head>
<body>
    <!-- Floating Background Elements -->
    <div class="floating-shapes">
        <div class="shape shape-1"></div>
        <div class="shape shape-2"></div>
        <div class="shape shape-3"></div>
        <div class="shape shape-4"></div>
        <div class="shape shape-5"></div>
    </div>

    <!-- Toast Notification -->
    <div class="toast" id="toast">
        <i class="fas fa-check-circle"></i>
        <span id="toast-message">Operation completed successfully!</span>
    </div>

    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <a href="#" class="logo">
                <div class="logo-icon">
                    <i class="fas fa-code"></i>
                </div>
                <span class="logo-text">CodeSphere</span>
            </a>
            <button class="sidebar-toggle" id="sidebarToggle">
                <i class="fas fa-chevron-left"></i>
            </button>
        </div>

        <div class="sidebar-nav">
            <div class="nav-section">
                <div class="nav-title">Navigation</div>
                <a href="dashboard.php" class="nav-item">
                    <i class="fas fa-home nav-icon"></i>
                    <span class="nav-text">Dashboard</span>
                </a>
                <a href="all_repos.php" class="nav-item">
                    <i class="fas fa-code-branch nav-icon"></i>
                    <span class="nav-text">All Repositories</span>
                    <span class="nav-badge"><?php echo $stats['total_repos']; ?></span>
                </a>
                <a href="repo.php" class="nav-item">
                    <i class="fas fa-plus-circle nav-icon"></i>
                    <span class="nav-text">New Repository</span>
                </a>
                <a href="code_editor.php" class="nav-item">
                    <i class="fas fa-code nav-icon"></i>
                    <span class="nav-text">Code Editor</span>
                </a>
            </div>

            <div class="nav-section">
                <div class="nav-title">Collaborate</div>
                <a href="collaborations.php" class="nav-item">
                    <i class="fas fa-users nav-icon"></i>
                    <span class="nav-text">Collaborations</span>
                    <span class="nav-badge"><?php echo $stats['collaborator_repos']; ?></span>
                </a>
                <a href="notifications.php" class="nav-item">
                    <i class="fas fa-bell nav-icon"></i>
                    <span class="nav-text">Notifications</span>
                    <span class="nav-badge" id="notificationCount"><?php echo $unreadNotificationCount; ?></span>
                </a>
                <a href="sharing.php" class="nav-item">
                    <i class="fas fa-share-alt nav-icon"></i>
                    <span class="nav-text">Share Repository</span>
                </a>
            </div>

            <div class="nav-section">
                <div class="nav-title">Insights</div>
                <a href="analytics.php" class="nav-item">
                    <i class="fas fa-chart-line nav-icon"></i>
                    <span class="nav-text">Analytics</span>
                </a>
                <a href="contributions.php" class="nav-item">
                    <i class="fas fa-fire-flame-curved nav-icon"></i>
                    <span class="nav-text">Contributions</span>
                    <span class="nav-badge"><?php echo $contributionStreak; ?></span>
                </a>
            </div>

            <div class="nav-section">
                <div class="nav-title">Account</div>
                <a href="profile.php" class="nav-item active">
                    <i class="fas fa-user-circle nav-icon"></i>
                    <span class="nav-text">Profile</span>
                </a>
                <a href="settings.php" class="nav-item">
                    <i class="fas fa-cog nav-icon"></i>
                    <span class="nav-text">Settings</span>
                </a>
                <a href="logout.php" class="nav-item">
                    <i class="fas fa-sign-out-alt nav-icon"></i>
                    <span class="nav-text">Logout</span>
                </a>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <!-- Header -->
        <header>
            <div class="top-nav">
                <button class="mobile-menu-btn" id="mobileMenuBtn">
                    <i class="fas fa-bars"></i>
                </button>

                <div class="search-bar">
                    <div class="search-container">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" class="search-input" placeholder="Search repositories, projects, or teams...">
                    </div>
                </div>

                <div class="nav-right">
                    <button class="nav-btn" title="Notifications" id="notificationBtn">
                        <i class="fas fa-bell"></i>
                        <?php if ($unreadNotificationCount > 0): ?>
                            <span class="notification-badge" id="headerNotificationCount"><?php echo $unreadNotificationCount; ?></span>
                        <?php endif; ?>
                    </button>
                    <button class="nav-btn theme-toggle" id="themeToggle" title="Toggle theme">
                        <i class="fas fa-moon"></i>
                    </button>
                    <div class="user-menu" onclick="window.location.href='account.php'">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($user['first_name'], 0, 1)) . strtoupper(substr($user['last_name'], 0, 1)); ?>
                        </div>
                        <div class="user-info">
                            <div class="user-name"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></div>
                            <div class="user-role">Developer</div>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- Profile Content -->
        <section class="profile">
            <!-- Success/Error Messages -->
            <?php if (isset($successMessage)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $successMessage; ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($errorMessage)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $errorMessage; ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($shareSuccess)): ?>
                <?php echo $shareSuccess; ?>
            <?php endif; ?>
            
            <?php if (isset($pdfSuccess)): ?>
                <div class="alert alert-success">
                    <ion-icon name="document-outline"></ion-icon> <?php echo $pdfSuccess; ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($sectionSuccess) && $sectionSuccess): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> 
                    <?php 
                    switch($sectionType) {
                        case 'skill': echo 'Skill added successfully!'; break;
                        case 'certification': echo 'Certification added successfully!'; break;
                        case 'interest': echo 'Interest added successfully!'; break;
                        case 'hobby': echo 'Hobby added successfully!'; break;
                        case 'language': echo 'Language added successfully!'; break;
                        case 'education': echo 'Education added successfully!'; break;
                        case 'experience': echo 'Experience added successfully!'; break;
                        case 'project': echo 'Project added successfully!'; break;
                        case 'publication': echo 'Publication added successfully!'; break;
                        case 'achievement': echo 'Achievement added successfully!'; break;
                        case 'volunteer': echo 'Volunteer work added successfully!'; break;
                        case 'mentorship': echo 'Mentorship area added successfully!'; break;
                        case 'learning_goal': echo 'Learning goal added successfully!'; break;
                        default: echo 'Item added successfully!';
                    }
                    ?>
                </div>
            <?php endif; ?>

            <div class="profile-header">
                <div class="profile-content">
                    <div class="profile-avatar">
                        <?php echo strtoupper(substr($user['first_name'], 0, 1)) . strtoupper(substr($user['last_name'], 0, 1)); ?>
                    </div>
                    <div class="profile-info">
                        <h1 class="profile-name">
                            <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                            <span class="profile-username">@<?php echo htmlspecialchars($user['username']); ?></span>
                            <?php if (!empty($userProfile['availability_status'])): ?>
                                <span class="availability-status status-<?php echo $userProfile['availability_status']; ?>">
                                    <span class="status-dot"></span>
                                    <?php echo ucfirst($userProfile['availability_status']); ?>
                                </span>
                            <?php endif; ?>
                        </h1>
                        <p class="profile-bio"><?php echo htmlspecialchars($userProfile['bio'] ?? 'Passionate developer building amazing things with code.'); ?></p>
                        <div class="profile-meta">
                            <?php if (!empty($userProfile['location'])): ?>
                            <div class="profile-meta-item">
                                <i class="fas fa-map-marker-alt"></i>
                                <span><?php echo htmlspecialchars($userProfile['location']); ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="profile-meta-item">
                                <i class="fas fa-calendar-alt"></i>
                                <span>Joined <?php echo date('F Y', strtotime($user['created_at'])); ?></span>
                            </div>
                            <div class="profile-meta-item">
                                <i class="fas fa-code"></i>
                                <span><?php echo $stats['total_repos']; ?> Repositories</span>
                            </div>
                            <div class="profile-meta-item">
                                <i class="fas fa-code-commit"></i>
                                <span><?php echo $stats['total_commits']; ?> Commits</span>
                            </div>
                        </div>
                        <div class="profile-actions">
                            <button class="btn btn-primary" id="editProfileBtn">
                                <ion-icon name="create-outline"></ion-icon>
                                Edit Profile
                            </button>
                            <form method="POST" style="display: inline;">
                                <button type="submit" name="share_profile" class="btn btn-secondary">
                                    <i class="fas fa-share-alt"></i>
                                    Share Profile
                                </button>
                            </form>
                            <form method="POST" style="display: inline;">
                                <button type="submit" name="download_pdf" class="btn btn-secondary">
                                    <ion-icon name="document-outline"></ion-icon>
                                    Download PDF
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Profile Tabs -->
            <div class="profile-tabs">
                <button class="profile-tab active" data-tab="overview">Overview</button>
                <button class="profile-tab" data-tab="skills">Skills & Expertise</button>
                <button class="profile-tab" data-tab="experience">Work Experience</button>
                <button class="profile-tab" data-tab="education">Education</button>
                <button class="profile-tab" data-tab="projects">Projects</button>
                <button class="profile-tab" data-tab="publications">Publications</button>
                <button class="profile-tab" data-tab="achievements">Achievements</button>
                <button class="profile-tab" data-tab="volunteer">Volunteer Work</button>
                <button class="profile-tab" data-tab="mentorship">Mentorship</button>
                <button class="profile-tab" data-tab="learning">Learning Goals</button>
            </div>

            <!-- Tab Contents -->
            <div class="tab-content active" id="overview-tab">
                <div class="profile-grid">
                    <!-- Statistics Card -->
                    <div class="profile-card">
                        <div class="card-header">
                            <h2><i class="fas fa-chart-bar card-icon"></i> Coding Statistics</h2>
                        </div>
                        <div class="card-body">
                            <div class="stats-grid">
                                <div class="stat-item">
                                    <div class="stat-value"><?php echo $stats['total_repos']; ?></div>
                                    <div class="stat-label">Repositories</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-value"><?php echo $stats['owner_repos']; ?></div>
                                    <div class="stat-label">Owned</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-value"><?php echo $stats['collaborator_repos']; ?></div>
                                    <div class="stat-label">Collaborating</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-value"><?php echo $stats['total_commits']; ?></div>
                                    <div class="stat-label">Commits</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-value"><?php echo $contributionStreak; ?></div>
                                    <div class="stat-label">Day Streak</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-value"><?php echo date_diff(date_create($user['created_at']), date_create('now'))->format('%a'); ?></div>
                                    <div class="stat-label">Days Active</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Personal Information Card -->
                    <div class="profile-card">
                        <div class="card-header">
                            <h2><i class="fas fa-user-circle card-icon"></i> Personal Information</h2>
                        </div>
                        <div class="card-body">
                            <ul class="info-list">
                                <li class="info-item">
                                    <div class="info-icon">
                                        <i class="fas fa-user"></i>
                                    </div>
                                    <div class="info-content">
                                        <div class="info-label">Username</div>
                                        <div class="info-value">@<?php echo htmlspecialchars($user['username']); ?></div>
                                    </div>
                                </li>
                                <li class="info-item">
                                    <div class="info-icon">
                                        <i class="fas fa-envelope"></i>
                                    </div>
                                    <div class="info-content">
                                        <div class="info-label">Email</div>
                                        <div class="info-value"><?php echo htmlspecialchars($user['email']); ?></div>
                                    </div>
                                </li>
                                <li class="info-item">
                                    <div class="info-icon">
                                        <i class="fas fa-user-tag"></i>
                                    </div>
                                    <div class="info-content">
                                        <div class="info-label">Full Name</div>
                                        <div class="info-value"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></div>
                                    </div>
                                </li>
                                <?php if (!empty($userProfile['company'])): ?>
                                <li class="info-item">
                                    <div class="info-icon">
                                        <i class="fas fa-building"></i>
                                    </div>
                                    <div class="info-content">
                                        <div class="info-label">Company</div>
                                        <div class="info-value"><?php echo htmlspecialchars($userProfile['company']); ?></div>
                                    </div>
                                </li>
                                <?php endif; ?>
                                <?php if (!empty($userProfile['job_title'])): ?>
                                <li class="info-item">
                                    <div class="info-icon">
                                        <i class="fas fa-briefcase"></i>
                                    </div>
                                    <div class="info-content">
                                        <div class="info-label">Job Title</div>
                                        <div class="info-value"><?php echo htmlspecialchars($userProfile['job_title']); ?></div>
                                    </div>
                                </li>
                                <?php endif; ?>
                                <?php if (!empty($userProfile['location'])): ?>
                                <li class="info-item">
                                    <div class="info-icon">
                                        <i class="fas fa-map-marker-alt"></i>
                                    </div>
                                    <div class="info-content">
                                        <div class="info-label">Location</div>
                                        <div class="info-value"><?php echo htmlspecialchars($userProfile['location']); ?></div>
                                    </div>
                                </li>
                                <?php endif; ?>
                                <?php if (!empty($userProfile['hourly_rate'])): ?>
                                <li class="info-item">
                                    <div class="info-icon">
                                        <i class="fas fa-dollar-sign"></i>
                                    </div>
                                    <div class="info-content">
                                        <div class="info-label">Hourly Rate</div>
                                        <div class="info-value hourly-rate">$<?php echo htmlspecialchars($userProfile['hourly_rate']); ?>/hr</div>
                                    </div>
                                </li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>

                    <!-- Languages Card -->
                    <div class="profile-card">
                        <div class="card-header">
                            <h2><i class="fas fa-language card-icon"></i> Languages</h2>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($userProfile['languages'])): ?>
                                <div class="tags">
                                    <?php 
                                    foreach ($languagesArray as $language): 
                                        if (trim($language)): 
                                            $languageName = trim($language);
                                            $level = $languageLevels[$languageName] ?? 'Intermediate';
                                    ?>
                                        <span class="tag languages-tag">
                                            <i class="fas fa-language"></i>
                                            <?php echo htmlspecialchars($languageName); ?>
                                            <small>(<?php echo htmlspecialchars($level); ?>)</small>
                                        </span>
                                    <?php 
                                        endif;
                                    endforeach; 
                                    ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-language"></i>
                                    <h3>No languages added</h3>
                                    <p>Add languages you speak or program in</p>
                                </div>
                            <?php endif; ?>
                            <button class="section-add-btn" data-modal="languageModal">
                                <ion-icon name="add-outline"></ion-icon>
                                Add Language
                            </button>
                        </div>
                    </div>

                    <!-- Social Links Card -->
                    <div class="profile-card">
                        <div class="card-header">
                            <h2><i class="fas fa-share-alt card-icon"></i> Social Links</h2>
                        </div>
                        <div class="card-body">
                            <ul class="info-list">
                                <?php if (!empty($userProfile['website'])): ?>
                                <li class="info-item">
                                    <div class="info-icon">
                                        <i class="fas fa-globe"></i>
                                    </div>
                                    <div class="info-content">
                                        <div class="info-label">Website</div>
                                        <div class="info-value">
                                            <a href="<?php echo htmlspecialchars($userProfile['website']); ?>" target="_blank"><?php echo htmlspecialchars($userProfile['website']); ?></a>
                                        </div>
                                    </div>
                                </li>
                                <?php endif; ?>
                                <?php if (!empty($userProfile['portfolio_url'])): ?>
                                <li class="info-item">
                                    <div class="info-icon">
                                        <i class="fas fa-briefcase"></i>
                                    </div>
                                    <div class="info-content">
                                        <div class="info-label">Portfolio</div>
                                        <div class="info-value">
                                            <a href="<?php echo htmlspecialchars($userProfile['portfolio_url']); ?>" target="_blank"><?php echo htmlspecialchars($userProfile['portfolio_url']); ?></a>
                                        </div>
                                    </div>
                                </li>
                                <?php endif; ?>
                                <?php if (!empty($userProfile['github_url'])): ?>
                                <li class="info-item">
                                    <div class="info-icon">
                                        <i class="fab fa-github"></i>
                                    </div>
                                    <div class="info-content">
                                        <div class="info-label">GitHub</div>
                                        <div class="info-value">
                                            <a href="<?php echo htmlspecialchars($userProfile['github_url']); ?>" target="_blank"><?php echo htmlspecialchars($userProfile['github_url']); ?></a>
                                        </div>
                                    </div>
                                </li>
                                <?php endif; ?>
                                <?php if (!empty($userProfile['twitter_url'])): ?>
                                <li class="info-item">
                                    <div class="info-icon">
                                        <i class="fab fa-twitter"></i>
                                    </div>
                                    <div class="info-content">
                                        <div class="info-label">Twitter</div>
                                        <div class="info-value">
                                            <a href="<?php echo htmlspecialchars($userProfile['twitter_url']); ?>" target="_blank"><?php echo htmlspecialchars($userProfile['twitter_url']); ?></a>
                                        </div>
                                    </div>
                                </li>
                                <?php endif; ?>
                                <?php if (!empty($userProfile['linkedin_url'])): ?>
                                <li class="info-item">
                                    <div class="info-icon">
                                        <i class="fab fa-linkedin"></i>
                                    </div>
                                    <div class="info-content">
                                        <div class="info-label">LinkedIn</div>
                                        <div class="info-value">
                                            <a href="<?php echo htmlspecialchars($userProfile['linkedin_url']); ?>" target="_blank"><?php echo htmlspecialchars($userProfile['linkedin_url']); ?></a>
                                        </div>
                                    </div>
                                </li>
                                <?php endif; ?>
                            </ul>
                            
                            <?php if (empty($userProfile['website']) && empty($userProfile['portfolio_url']) && empty($userProfile['github_url']) && empty($userProfile['twitter_url']) && empty($userProfile['linkedin_url'])): ?>
                                <div class="empty-state">
                                    <i class="fas fa-share-alt"></i>
                                    <h3>No social links added</h3>
                                    <p>Add your social profiles to connect with others</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Skills & Expertise Tab -->
            <div class="tab-content" id="skills-tab">
                <div class="profile-grid">
                    <div class="profile-card">
                        <div class="card-header">
                            <h2><i class="fas fa-code card-icon"></i> Technical Skills</h2>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($userProfile['skills'])): ?>
                                <div class="tags">
                                    <?php 
                                    foreach ($skillsArray as $skill): 
                                        if (trim($skill)): 
                                            $skillName = trim($skill);
                                            $level = $skillLevels[$skillName] ?? 'Intermediate';
                                    ?>
                                        <span class="tag skills-tag">
                                            <i class="fas fa-code"></i>
                                            <?php echo htmlspecialchars($skillName); ?>
                                            <small>(<?php echo htmlspecialchars($level); ?>)</small>
                                        </span>
                                    <?php 
                                        endif;
                                    endforeach; 
                                    ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-code"></i>
                                    <h3>No skills added</h3>
                                    <p>Add your technical skills and programming languages</p>
                                </div>
                            <?php endif; ?>
                            <button class="section-add-btn" data-modal="skillModal">
                                <ion-icon name="add-outline"></ion-icon>
                                Add Skill
                            </button>
                        </div>
                    </div>

                    <div class="profile-card">
                        <div class="card-header">
                            <h2><i class="fas fa-star card-icon"></i> Interests</h2>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($userProfile['interests'])): ?>
                                <div class="tags">
                                    <?php 
                                    foreach ($interestsArray as $interest): 
                                        if (trim($interest)): 
                                    ?>
                                        <span class="tag interests-tag">
                                            <i class="fas fa-star"></i>
                                            <?php echo htmlspecialchars(trim($interest)); ?>
                                        </span>
                                    <?php 
                                        endif;
                                    endforeach; 
                                    ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-star"></i>
                                    <h3>No interests added</h3>
                                    <p>Add topics and areas that interest you</p>
                                </div>
                            <?php endif; ?>
                            <button class="section-add-btn" data-modal="interestModal">
                                <ion-icon name="add-outline"></ion-icon>
                                Add Interest
                            </button>
                        </div>
                    </div>

                    <div class="profile-card">
                        <div class="card-header">
                            <h2><i class="fas fa-heart card-icon"></i> Hobbies</h2>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($userProfile['hobbies'])): ?>
                                <div class="tags">
                                    <?php 
                                    foreach ($hobbiesArray as $hobby): 
                                        if (trim($hobby)): 
                                    ?>
                                        <span class="tag hobbies-tag">
                                            <i class="fas fa-heart"></i>
                                            <?php echo htmlspecialchars(trim($hobby)); ?>
                                        </span>
                                    <?php 
                                        endif;
                                    endforeach; 
                                    ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-heart"></i>
                                    <h3>No hobbies added</h3>
                                    <p>Add your favorite hobbies and activities</p>
                                </div>
                            <?php endif; ?>
                            <button class="section-add-btn" data-modal="hobbyModal">
                                <ion-icon name="add-outline"></ion-icon>
                                Add Hobby
                            </button>
                        </div>
                    </div>

                    <div class="profile-card">
                        <div class="card-header">
                            <h2><i class="fas fa-award card-icon"></i> Certifications</h2>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($userProfile['certifications'])): ?>
                                <div class="tags">
                                    <?php 
                                    foreach ($certificationsArray as $cert): 
                                        if (trim($cert)): 
                                    ?>
                                        <span class="tag certifications-tag">
                                            <i class="fas fa-award"></i>
                                            <?php echo htmlspecialchars(trim($cert)); ?>
                                        </span>
                                    <?php 
                                        endif;
                                    endforeach; 
                                    ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-award"></i>
                                    <h3>No certifications added</h3>
                                    <p>Add your professional certifications</p>
                                </div>
                            <?php endif; ?>
                            <button class="section-add-btn" data-modal="certificationModal">
                                <ion-icon name="add-outline"></ion-icon>
                                Add Certification
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Work Experience Tab -->
            <div class="tab-content" id="experience-tab">
                <div class="profile-card">
                    <div class="card-header">
                        <h2><i class="fas fa-briefcase card-icon"></i> Work Experience</h2>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($userProfile['work_experience'])): ?>
                            <div class="timeline">
                                <?php 
                                foreach ($experienceArray as $item): 
                                    if (trim($item)):
                                        $parts = explode('|', $item);
                                        if (count($parts) >= 4):
                                ?>
                                    <div class="timeline-item">
                                        <div class="timeline-date"><?php echo htmlspecialchars(trim($parts[2])); ?></div>
                                        <div class="timeline-content">
                                            <div class="timeline-title"><?php echo htmlspecialchars(trim($parts[0])); ?> at <?php echo htmlspecialchars(trim($parts[1])); ?></div>
                                            <div class="timeline-description"><?php echo htmlspecialchars(trim($parts[3])); ?></div>
                                        </div>
                                    </div>
                                <?php 
                                        endif;
                                    endif;
                                endforeach; 
                                ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-briefcase"></i>
                                <h3>No work experience added</h3>
                                <p>Add your professional work experience</p>
                            </div>
                        <?php endif; ?>
                        <button class="section-add-btn" data-modal="experienceModal">
                            <ion-icon name="add-outline"></ion-icon>
                            Add Experience
                        </button>
                    </div>
                </div>
            </div>

            <!-- Education Tab -->
            <div class="tab-content" id="education-tab">
                <div class="profile-grid">
                    <div class="profile-card">
                        <div class="card-header">
                            <h2><i class="fas fa-graduation-cap card-icon"></i> Education</h2>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($userProfile['education'])): ?>
                                <div class="timeline">
                                    <?php 
                                    foreach ($educationArray as $item): 
                                        if (trim($item)):
                                            $parts = explode('|', $item);
                                            if (count($parts) >= 3):
                                    ?>
                                        <div class="timeline-item">
                                            <div class="timeline-date"><?php echo htmlspecialchars(trim($parts[2])); ?></div>
                                            <div class="timeline-content">
                                                <div class="timeline-title"><?php echo htmlspecialchars(trim($parts[0])); ?></div>
                                                <div class="timeline-description"><?php echo htmlspecialchars(trim($parts[1])); ?></div>
                                            </div>
                                        </div>
                                    <?php 
                                            endif;
                                        endif;
                                    endforeach; 
                                    ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-state">
                                    <ion-icon name="school-outline"></ion-icon>
                                    <h3>No education information added</h3>
                                    <p>Add your educational background</p>
                                </div>
                            <?php endif; ?>
                            <button class="section-add-btn" data-modal="educationModal">
                                <ion-icon name="add-outline"></ion-icon>
                                Add Education
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Projects Tab -->
            <div class="tab-content" id="projects-tab">
                <div class="profile-card">
                    <div class="card-header">
                        <h2><i class="fas fa-project-diagram card-icon"></i> Projects</h2>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($userProfile['projects'])): ?>
                            <div class="projects-grid">
                                <?php 
                                foreach ($projectsArray as $item): 
                                    if (trim($item)):
                                        $parts = explode('|', $item);
                                        if (count($parts) >= 3):
                                ?>
                                    <div class="project-card">
                                        <div class="project-header">
                                            <div class="project-title"><?php echo htmlspecialchars(trim($parts[0])); ?></div>
                                            <div class="project-description"><?php echo htmlspecialchars(trim($parts[1])); ?></div>
                                        </div>
                                        <div class="project-body">
                                            <div class="project-tech">
                                                <?php 
                                                if (count($parts) > 3) {
                                                    $techs = explode(',', $parts[3]);
                                                    foreach ($techs as $tech):
                                                        if (trim($tech)):
                                                ?>
                                                    <span class="tag"><?php echo htmlspecialchars(trim($tech)); ?></span>
                                                <?php 
                                                        endif;
                                                    endforeach;
                                                }
                                                ?>
                                            </div>
                                            <?php if (count($parts) > 2 && !empty(trim($parts[2]))): ?>
                                                <a href="<?php echo htmlspecialchars(trim($parts[2])); ?>" class="project-link" target="_blank">
                                                    <ion-icon name="open-outline"></ion-icon>
                                                    View Project
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php 
                                        endif;
                                    endif;
                                endforeach; 
                                ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-project-diagram"></i>
                                <h3>No projects added</h3>
                                <p>Showcase your personal or professional projects</p>
                            </div>
                        <?php endif; ?>
                        <button class="section-add-btn" data-modal="projectModal">
                            <ion-icon name="add-outline"></ion-icon>
                            Add Project
                        </button>
                    </div>
                </div>
            </div>

            <!-- Publications Tab -->
            <div class="tab-content" id="publications-tab">
                <div class="profile-card">
                    <div class="card-header">
                        <h2><i class="fas fa-book card-icon"></i> Publications</h2>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($userProfile['publications'])): ?>
                            <ul class="publication-list">
                                <?php 
                                foreach ($publicationsArray as $item): 
                                    if (trim($item)):
                                        $parts = explode('|', $item);
                                        if (count($parts) >= 3):
                                ?>
                                    <li class="publication-item">
                                        <div class="publication-title"><?php echo htmlspecialchars(trim($parts[0])); ?></div>
                                        <div class="publication-meta"><?php echo htmlspecialchars(trim($parts[1])); ?> (<?php echo htmlspecialchars(trim($parts[2])); ?>)</div>
                                        <div class="publication-description"><?php echo htmlspecialchars(trim($parts[3] ?? '')); ?></div>
                                        <?php if (count($parts) > 4 && !empty(trim($parts[4]))): ?>
                                            <a href="<?php echo htmlspecialchars(trim($parts[4])); ?>" class="publication-link" target="_blank">
                                                <ion-icon name="open-outline"></ion-icon>
                                                Read Publication
                                            </a>
                                        <?php endif; ?>
                                    </li>
                                <?php 
                                        endif;
                                    endif;
                                endforeach; 
                                ?>
                            </ul>
                        <?php else: ?>
                            <div class="empty-state">
                                <ion-icon name="book-outline"></ion-icon>
                                <h3>No publications added</h3>
                                <p>Add your research papers, articles, or blog posts</p>
                            </div>
                        <?php endif; ?>
                        <button class="section-add-btn" data-modal="publicationModal">
                            <ion-icon name="add-outline"></ion-icon>
                            Add Publication
                        </button>
                    </div>
                </div>
            </div>

            <!-- Achievements Tab -->
            <div class="tab-content" id="achievements-tab">
                <div class="profile-grid">
                    <div class="profile-card">
                        <div class="card-header">
                            <h2><i class="fas fa-trophy card-icon"></i> Achievements</h2>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($userProfile['achievements'])): ?>
                                <div class="badges-container">
                                    <?php 
                                    foreach ($achievementsArray as $achievement): 
                                        if (trim($achievement)): 
                                    ?>
                                        <div class="badge">
                                            <div class="badge-icon">
                                                <i class="fas fa-trophy"></i>
                                            </div>
                                            <div class="badge-title"><?php echo htmlspecialchars(trim($achievement)); ?></div>
                                            <div class="badge-description">Great achievement!</div>
                                        </div>
                                    <?php 
                                        endif;
                                    endforeach; 
                                    ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-trophy"></i>
                                    <h3>No achievements added</h3>
                                    <p>Add your professional or personal achievements</p>
                                </div>
                            <?php endif; ?>
                            <button class="section-add-btn" data-modal="achievementModal">
                                <ion-icon name="add-outline"></ion-icon>
                                Add Achievement
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Volunteer Work Tab -->
            <div class="tab-content" id="volunteer-tab">
                <div class="profile-card">
                    <div class="card-header">
                        <h2><i class="fas fa-hands-helping card-icon"></i> Volunteer Work</h2>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($userProfile['volunteer_work'])): ?>
                            <div class="volunteer-grid">
                                <?php 
                                foreach ($volunteerArray as $item): 
                                    if (trim($item)):
                                        $parts = explode('|', $item);
                                        if (count($parts) >= 3):
                                ?>
                                    <div class="volunteer-card">
                                        <div class="volunteer-role"><?php echo htmlspecialchars(trim($parts[0])); ?></div>
                                        <div class="volunteer-organization"><?php echo htmlspecialchars(trim($parts[1])); ?></div>
                                        <div class="volunteer-duration"><?php echo htmlspecialchars(trim($parts[2])); ?></div>
                                        <?php if (count($parts) > 3): ?>
                                            <div class="volunteer-description"><?php echo htmlspecialchars(trim($parts[3])); ?></div>
                                        <?php endif; ?>
                                    </div>
                                <?php 
                                        endif;
                                    endif;
                                endforeach; 
                                ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-hands-helping"></i>
                                <h3>No volunteer work added</h3>
                                <p>Add your volunteer experience and community contributions</p>
                            </div>
                        <?php endif; ?>
                        <button class="section-add-btn" data-modal="volunteerModal">
                            <ion-icon name="add-outline"></ion-icon>
                            Add Volunteer Work
                        </button>
                    </div>
                </div>
            </div>

            <!-- Mentorship Tab -->
            <div class="tab-content" id="mentorship-tab">
                <div class="profile-card">
                    <div class="card-header">
                        <h2><i class="fas fa-user-graduate card-icon"></i> Mentorship</h2>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($userProfile['mentorship'])): ?>
                            <div class="mentorship-areas">
                                <?php 
                                foreach ($mentorshipArray as $area): 
                                    if (trim($area)): 
                                ?>
                                    <span class="tag mentorship-tag">
                                        <ion-icon name="people-outline"></ion-icon>
                                        <?php echo htmlspecialchars(trim($area)); ?>
                                    </span>
                                <?php 
                                    endif;
                                endforeach; 
                                ?>
                            </div>
                            <div class="form-help" style="margin-top: 1rem;">
                                <i class="fas fa-info-circle"></i>
                                Available for mentorship in these areas
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <ion-icon name="people-outline"></ion-icon>
                                <h3>No mentorship areas specified</h3>
                                <p>Add areas where you can mentor others</p>
                            </div>
                        <?php endif; ?>
                        <button class="section-add-btn" data-modal="mentorshipModal">
                            <ion-icon name="add-outline"></ion-icon>
                            Add Mentorship Area
                        </button>
                    </div>
                </div>
            </div>

            <!-- Learning Goals Tab -->
            <div class="tab-content" id="learning-tab">
                <div class="profile-card">
                    <div class="card-header">
                        <h2><i class="fas fa-bullseye card-icon"></i> Learning Goals</h2>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($userProfile['learning_goals'])): ?>
                            <div class="learning-goals">
                                <?php 
                                foreach ($learningGoalsArray as $goal): 
                                    if (trim($goal)):
                                        $parts = explode('|', $goal);
                                        if (count($parts) >= 2):
                                            $progress = isset($parts[2]) ? intval($parts[2]) : 0;
                                ?>
                                    <div class="goal-item">
                                        <div class="goal-header">
                                            <div class="goal-title"><?php echo htmlspecialchars(trim($parts[0])); ?></div>
                                            <div class="goal-progress"><?php echo $progress; ?>%</div>
                                        </div>
                                        <div class="goal-description"><?php echo htmlspecialchars(trim($parts[1])); ?></div>
                                        <div class="goal-bar">
                                            <div class="goal-progress-bar" style="width: <?php echo $progress; ?>%;"></div>
                                        </div>
                                    </div>
                                <?php 
                                        endif;
                                    endif;
                                endforeach; 
                                ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-bullseye"></i>
                                <h3>No learning goals added</h3>
                                <p>Add your current learning objectives and track your progress</p>
                            </div>
                        <?php endif; ?>
                        <button class="section-add-btn" data-modal="learningGoalModal">
                            <ion-icon name="add-outline"></ion-icon>
                            Add Learning Goal
                        </button>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <!-- Enhanced Edit Profile Modal with Tabs -->
    <div class="modal-overlay" id="editProfileModal">
        <div class="modal">
            <div class="modal-header">
                <h3>Edit Profile</h3>
                <button class="modal-close" id="editProfileClose">
                    <ion-icon name="close-outline"></ion-icon>
                </button>
            </div>
            
            <div class="modal-tabs">
                <button class="modal-tab active" data-modal-tab="basic">Basic Info</button>
                <button class="modal-tab" data-modal-tab="skills">Skills</button>
                <button class="modal-tab" data-modal-tab="experience">Experience</button>
                <button class="modal-tab" data-modal-tab="education">Education</button>
                <button class="modal-tab" data-modal-tab="projects">Projects</button>
                <button class="modal-tab" data-modal-tab="social">Social & Appearance</button>
            </div>
            
            <form method="POST" id="editProfileForm">
                <div class="modal-body">
                    <!-- Basic Info Tab -->
                    <div class="modal-tab-content active" id="basic-tab">
                        <div class="form-card">
                            <div class="form-card-title">
                                <i class="fas fa-user"></i>
                                Personal Information
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="bio">Bio</label>
                                <textarea class="form-textarea" id="bio" name="bio" placeholder="Tell us about yourself..."><?php echo htmlspecialchars($userProfile['bio'] ?? ''); ?></textarea>
                                <div class="form-help">Brief description of yourself (max 500 characters)</div>
                            </div>

                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label" for="location">Location</label>
                                    <div class="input-with-icon">
                                        <i class="fas fa-map-marker-alt input-icon"></i>
                                        <input type="text" class="form-input" id="location" name="location" placeholder="City, Country" value="<?php echo htmlspecialchars($userProfile['location'] ?? ''); ?>">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label" for="company">Company</label>
                                    <div class="input-with-icon">
                                        <i class="fas fa-building input-icon"></i>
                                        <input type="text" class="form-input" id="company" name="company" placeholder="Your company" value="<?php echo htmlspecialchars($userProfile['company'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label" for="job_title">Job Title</label>
                                    <div class="input-with-icon">
                                        <i class="fas fa-briefcase input-icon"></i>
                                        <input type="text" class="form-input" id="job_title" name="job_title" placeholder="Your job title" value="<?php echo htmlspecialchars($userProfile['job_title'] ?? ''); ?>">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label" for="hourly_rate">Hourly Rate ($)</label>
                                    <div class="input-with-icon">
                                        <i class="fas fa-dollar-sign input-icon"></i>
                                        <input type="number" class="form-input" id="hourly_rate" name="hourly_rate" placeholder="50" step="0.01" min="0" value="<?php echo htmlspecialchars($userProfile['hourly_rate'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-card">
                            <div class="form-card-title">
                                <i class="fas fa-user-check"></i>
                                Availability & Preferences
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="availability_status">Availability Status</label>
                                <select class="form-select" id="availability_status" name="availability_status">
                                    <option value="available" <?php echo ($userProfile['availability_status'] ?? 'available') === 'available' ? 'selected' : ''; ?>>Available</option>
                                    <option value="busy" <?php echo ($userProfile['availability_status'] ?? '') === 'busy' ? 'selected' : ''; ?>>Busy</option>
                                    <option value="away" <?php echo ($userProfile['availability_status'] ?? '') === 'away' ? 'selected' : ''; ?>>Away</option>
                                    <option value="offline" <?php echo ($userProfile['availability_status'] ?? '') === 'offline' ? 'selected' : ''; ?>>Offline</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <div class="checkbox-group">
                                    <input type="checkbox" class="checkbox" id="is_hireable" name="is_hireable" <?php echo ($userProfile['is_hireable'] ?? 0) ? 'checked' : ''; ?>>
                                    <label for="is_hireable" class="form-label">Open to job opportunities</label>
                                </div>
                                <div class="form-help">Show that you're available for new opportunities</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Skills Tab -->
                    <div class="modal-tab-content" id="skills-tab">
                        <div class="form-card">
                            <div class="form-card-title">
                                <i class="fas fa-code"></i>
                                Technical Skills & Languages
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="skills">Technical Skills</label>
                                <div class="input-with-icon">
                                    <i class="fas fa-code input-icon"></i>
                                    <input type="text" class="form-input" id="skills" name="skills" placeholder="JavaScript, PHP, Python, React..." value="<?php echo htmlspecialchars($userProfile['skills'] ?? ''); ?>">
                                </div>
                                <div class="form-help">Separate skills with commas</div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="languages">Languages</label>
                                <div class="input-with-icon">
                                    <i class="fas fa-language input-icon"></i>
                                    <input type="text" class="form-input" id="languages" name="languages" placeholder="English, Spanish, French..." value="<?php echo htmlspecialchars($userProfile['languages'] ?? ''); ?>">
                                </div>
                                <div class="form-help">Languages you speak or program in</div>
                            </div>
                        </div>

                        <div class="form-card">
                            <div class="form-card-title">
                                <i class="fas fa-star"></i>
                                Interests & Hobbies
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="interests">Interests</label>
                                <div class="input-with-icon">
                                    <i class="fas fa-star input-icon"></i>
                                    <input type="text" class="form-input" id="interests" name="interests" placeholder="Web Development, AI, Open Source..." value="<?php echo htmlspecialchars($userProfile['interests'] ?? ''); ?>">
                                </div>
                                <div class="form-help">Topics and areas that interest you</div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="hobbies">Hobbies</label>
                                <div class="input-with-icon">
                                    <i class="fas fa-heart input-icon"></i>
                                    <input type="text" class="form-input" id="hobbies" name="hobbies" placeholder="Photography, Hiking, Gaming..." value="<?php echo htmlspecialchars($userProfile['hobbies'] ?? ''); ?>">
                                </div>
                                <div class="form-help">Your favorite hobbies and activities</div>
                            </div>
                        </div>

                        <div class="form-card">
                            <div class="form-card-title">
                                <i class="fas fa-award"></i>
                                Certifications & Mentorship
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="certifications">Certifications</label>
                                <div class="input-with-icon">
                                    <i class="fas fa-award input-icon"></i>
                                    <input type="text" class="form-input" id="certifications" name="certifications" placeholder="AWS Certified, Google Analytics, PMP..." value="<?php echo htmlspecialchars($userProfile['certifications'] ?? ''); ?>">
                                </div>
                                <div class="form-help">Separate certifications with commas</div>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="mentorship">Mentorship Areas</label>
                                <div class="input-with-icon">
                                    <i class="fas fa-user-graduate input-icon"></i>
                                    <input type="text" class="form-input" id="mentorship" name="mentorship" placeholder="Web Development, Career Advice, Open Source..." value="<?php echo htmlspecialchars($userProfile['mentorship'] ?? ''); ?>">
                                </div>
                                <div class="form-help">Areas where you can mentor others</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Experience Tab -->
                    <div class="modal-tab-content" id="experience-tab">
                        <div class="form-card">
                            <div class="form-card-title">
                                <i class="fas fa-briefcase"></i>
                                Work Experience
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="work_experience">Work Experience</label>
                                <textarea class="form-textarea" id="work_experience" name="work_experience" placeholder="2020-Present | Senior Developer | Tech Company | Developed web applications...&#10;2018-2020 | Junior Developer | Startup Inc | Built responsive websites..."><?php echo htmlspecialchars($userProfile['work_experience'] ?? ''); ?></textarea>
                                <div class="form-help">Enter each experience on a new line in format: Dates | Position | Company | Description</div>
                            </div>
                        </div>

                        <div class="form-card">
                            <div class="form-card-title">
                                <i class="fas fa-hands-helping"></i>
                                Volunteer Work
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="volunteer_work">Volunteer Work</label>
                                <textarea class="form-textarea" id="volunteer_work" name="volunteer_work" placeholder="Volunteer Role | Organization | 2022-Present | Helped with community events...&#10;Mentor | Coding Bootcamp | 2021-2022 | Guided students through projects..."><?php echo htmlspecialchars($userProfile['volunteer_work'] ?? ''); ?></textarea>
                                <div class="form-help">Enter each volunteer experience on a new line in format: Role | Organization | Dates | Description</div>
                            </div>
                        </div>

                        <div class="form-card">
                            <div class="form-card-title">
                                <i class="fas fa-trophy"></i>
                                Achievements
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="achievements">Achievements</label>
                                <div class="input-with-icon">
                                    <i class="fas fa-trophy input-icon"></i>
                                    <input type="text" class="form-input" id="achievements" name="achievements" placeholder="Hackathon Winner, Open Source Contributor, Speaker..." value="<?php echo htmlspecialchars($userProfile['achievements'] ?? ''); ?>">
                                </div>
                                <div class="form-help">Separate achievements with commas</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Education Tab -->
                    <div class="modal-tab-content" id="education-tab">
                        <div class="form-card">
                            <div class="form-card-title">
                                <ion-icon name="school-outline"></ion-icon>
                                Education
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="education">Education</label>
                                <textarea class="form-textarea" id="education" name="education" placeholder="2020-2024 | Bachelor of Computer Science | University Name&#10;2018-2020 | High School Diploma | School Name"><?php echo htmlspecialchars($userProfile['education'] ?? ''); ?></textarea>
                                <div class="form-help">Enter each education entry on a new line in format: Dates | Degree/Program | Institution</div>
                            </div>
                        </div>

                        <div class="form-card">
                            <div class="form-card-title">
                                <ion-icon name="book-outline"></ion-icon>
                                Publications
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="publications">Publications</label>
                                <textarea class="form-textarea" id="publications" name="publications" placeholder="Research Paper Title | Journal Name, 2023 | Abstract description... | https://link-to-paper.com&#10;Blog Post Title | Personal Blog, 2022 | Summary of the post... | https://blog-link.com"><?php echo htmlspecialchars($userProfile['publications'] ?? ''); ?></textarea>
                                <div class="form-help">Enter each publication on a new line in format: Title | Source/Date | Description | URL</div>
                            </div>
                        </div>

                        <div class="form-card">
                            <div class="form-card-title">
                                <i class="fas fa-bullseye"></i>
                                Learning Goals
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="learning_goals">Learning Goals</label>
                                <textarea class="form-textarea" id="learning_goals" name="learning_goals" placeholder="Learn React Native | Master mobile development with React Native | 75&#10;Complete AWS Certification | Get AWS Solutions Architect certification | 25"><?php echo htmlspecialchars($userProfile['learning_goals'] ?? ''); ?></textarea>
                                <div class="form-help">Enter each goal on a new line in format: Goal Title | Description | Progress Percentage</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Projects Tab -->
                    <div class="modal-tab-content" id="projects-tab">
                        <div class="form-card">
                            <div class="form-card-title">
                                <i class="fas fa-project-diagram"></i>
                                Projects
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="projects">Projects</label>
                                <textarea class="form-textarea" id="projects" name="projects" placeholder="Project Name | Description | https://project-link.com | Tech1, Tech2, Tech3&#10;Another Project | Another description | https://another-link.com | React, Node.js, MongoDB"><?php echo htmlspecialchars($userProfile['projects'] ?? ''); ?></textarea>
                                <div class="form-help">Enter each project on a new line in format: Project Name | Description | URL | Technologies (comma separated)</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Social & Appearance Tab -->
                    <div class="modal-tab-content" id="social-tab">
                        <div class="form-card">
                            <div class="form-card-title">
                                <i class="fas fa-share-alt"></i>
                                Social Links
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="website">Website</label>
                                <div class="input-with-icon">
                                    <i class="fas fa-globe input-icon"></i>
                                    <input type="url" class="form-input" id="website" name="website" placeholder="https://yourwebsite.com" value="<?php echo htmlspecialchars($userProfile['website'] ?? ''); ?>">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="portfolio_url">Portfolio URL</label>
                                <div class="input-with-icon">
                                    <i class="fas fa-briefcase input-icon"></i>
                                    <input type="url" class="form-input" id="portfolio_url" name="portfolio_url" placeholder="https://yourportfolio.com" value="<?php echo htmlspecialchars($userProfile['portfolio_url'] ?? ''); ?>">
                                </div>
                            </div>

                            <div class="social-links">
                                <div class="social-link-group">
                                    <label class="form-label" for="github_url">GitHub URL</label>
                                    <div class="input-with-icon">
                                        <i class="fab fa-github input-icon"></i>
                                        <input type="url" class="form-input" id="github_url" name="github_url" placeholder="https://github.com/username" value="<?php echo htmlspecialchars($userProfile['github_url'] ?? ''); ?>">
                                    </div>
                                </div>

                                <div class="social-link-group">
                                    <label class="form-label" for="twitter_url">Twitter URL</label>
                                    <div class="input-with-icon">
                                        <i class="fab fa-twitter input-icon"></i>
                                        <input type="url" class="form-input" id="twitter_url" name="twitter_url" placeholder="https://twitter.com/username" value="<?php echo htmlspecialchars($userProfile['twitter_url'] ?? ''); ?>">
                                    </div>
                                </div>

                                <div class="social-link-group">
                                    <label class="form-label" for="linkedin_url">LinkedIn URL</label>
                                    <div class="input-with-icon">
                                        <i class="fab fa-linkedin input-icon"></i>
                                        <input type="url" class="form-input" id="linkedin_url" name="linkedin_url" placeholder="https://linkedin.com/in/username" value="<?php echo htmlspecialchars($userProfile['linkedin_url'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-card">
                            <div class="form-card-title">
                                <i class="fas fa-palette"></i>
                                Appearance
                            </div>
                            <div class="form-group">
                                <label class="form-label">Profile Color</label>
                                <div class="color-picker">
                                    <div class="color-option selected" style="background: #6366f1;" data-color="#6366f1"></div>
                                    <div class="color-option" style="background: #8b5cf6;" data-color="#8b5cf6"></div>
                                    <div class="color-option" style="background: #10b981;" data-color="#10b981"></div>
                                    <div class="color-option" style="background: #f59e0b;" data-color="#f59e0b"></div>
                                    <div class="color-option" style="background: #ef4444;" data-color="#ef4444"></div>
                                    <div class="color-option" style="background: #ec4899;" data-color="#ec4899"></div>
                                    <div class="color-option" style="background: #06b6d4;" data-color="#06b6d4"></div>
                                    <div class="color-option" style="background: #84cc16;" data-color="#84cc16"></div>
                                </div>
                                <input type="hidden" id="avatar_color" name="avatar_color" value="<?php echo htmlspecialchars($userProfile['avatar_color'] ?? '#6366f1'); ?>">
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="theme_preference">Theme Preference</label>
                                <select class="form-select" id="theme_preference" name="theme_preference">
                                    <option value="auto" <?php echo ($userProfile['theme_preference'] ?? 'auto') === 'auto' ? 'selected' : ''; ?>>Auto (System Default)</option>
                                    <option value="light" <?php echo ($userProfile['theme_preference'] ?? '') === 'light' ? 'selected' : ''; ?>>Light</option>
                                    <option value="dark" <?php echo ($userProfile['theme_preference'] ?? '') === 'dark' ? 'selected' : ''; ?>>Dark</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-card">
                            <div class="form-card-title">
                                <i class="fas fa-eye"></i>
                                Privacy Settings
                            </div>
                            <div class="form-group">
                                <div class="checkbox-group">
                                    <input type="checkbox" class="checkbox" id="is_public" name="is_public" <?php echo ($userProfile['is_public'] ?? 1) ? 'checked' : ''; ?>>
                                    <label for="is_public" class="form-label">Make profile public</label>
                                </div>
                                <div class="form-help">Allow other users to view your profile</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" id="cancelEdit">Cancel</button>
                    <button type="submit" name="update_profile" class="btn btn-success">
                        <ion-icon name="save-outline"></ion-icon>
                        Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Individual Section Modals -->
    <!-- Skill Modal -->
    <div class="modal-overlay" id="skillModal">
        <div class="modal">
            <div class="modal-header">
                <h3>Add Skill</h3>
                <button class="modal-close" data-modal="skillModal">
                    <ion-icon name="close-outline"></ion-icon>
                </button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label" for="new_skill">Skill Name</label>
                        <input type="text" class="form-input" id="new_skill" name="new_skill" placeholder="e.g., JavaScript, React, Python" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Skill Level</label>
                        <div class="skill-level-selector">
                            <div class="level-option selected" data-level="beginner">Beginner</div>
                            <div class="level-option" data-level="intermediate">Intermediate</div>
                            <div class="level-option" data-level="advanced">Advanced</div>
                            <div class="level-option" data-level="expert">Expert</div>
                        </div>
                        <input type="hidden" id="skill_level" name="skill_level" value="beginner">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" data-modal="skillModal">Cancel</button>
                    <button type="submit" name="add_skill" class="btn btn-success">Add Skill</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Certification Modal -->
    <div class="modal-overlay" id="certificationModal">
        <div class="modal">
            <div class="modal-header">
                <h3>Add Certification</h3>
                <button class="modal-close" data-modal="certificationModal">
                    <ion-icon name="close-outline"></ion-icon>
                </button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label" for="new_certification">Certification Name</label>
                        <input type="text" class="form-input" id="new_certification" name="new_certification" placeholder="e.g., AWS Certified Solutions Architect" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" data-modal="certificationModal">Cancel</button>
                    <button type="submit" name="add_certification" class="btn btn-success">Add Certification</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Interest Modal -->
    <div class="modal-overlay" id="interestModal">
        <div class="modal">
            <div class="modal-header">
                <h3>Add Interest</h3>
                <button class="modal-close" data-modal="interestModal">
                    <ion-icon name="close-outline"></ion-icon>
                </button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label" for="new_interest">Interest</label>
                        <input type="text" class="form-input" id="new_interest" name="new_interest" placeholder="e.g., Machine Learning, Open Source, UI/UX Design" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" data-modal="interestModal">Cancel</button>
                    <button type="submit" name="add_interest" class="btn btn-success">Add Interest</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Hobby Modal -->
    <div class="modal-overlay" id="hobbyModal">
        <div class="modal">
            <div class="modal-header">
                <h3>Add Hobby</h3>
                <button class="modal-close" data-modal="hobbyModal">
                    <ion-icon name="close-outline"></ion-icon>
                </button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label" for="new_hobby">Hobby</label>
                        <input type="text" class="form-input" id="new_hobby" name="new_hobby" placeholder="e.g., Photography, Hiking, Gaming" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" data-modal="hobbyModal">Cancel</button>
                    <button type="submit" name="add_hobby" class="btn btn-success">Add Hobby</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Language Modal -->
    <div class="modal-overlay" id="languageModal">
        <div class="modal">
            <div class="modal-header">
                <h3>Add Language</h3>
                <button class="modal-close" data-modal="languageModal">
                    <ion-icon name="close-outline"></ion-icon>
                </button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label" for="new_language">Language</label>
                        <input type="text" class="form-input" id="new_language" name="new_language" placeholder="e.g., English, Spanish, French" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Proficiency Level</label>
                        <div class="skill-level-selector">
                            <div class="level-option selected" data-level="beginner">Beginner</div>
                            <div class="level-option" data-level="intermediate">Intermediate</div>
                            <div class="level-option" data-level="advanced">Advanced</div>
                            <div class="level-option" data-level="native">Native</div>
                        </div>
                        <input type="hidden" id="language_level" name="language_level" value="beginner">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" data-modal="languageModal">Cancel</button>
                    <button type="submit" name="add_language" class="btn btn-success">Add Language</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Education Modal -->
    <div class="modal-overlay" id="educationModal">
        <div class="modal">
            <div class="modal-header">
                <h3>Add Education</h3>
                <button class="modal-close" data-modal="educationModal">
                    <ion-icon name="close-outline"></ion-icon>
                </button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label" for="education_degree">Degree/Program</label>
                        <input type="text" class="form-input" id="education_degree" name="education_degree" placeholder="e.g., Bachelor of Computer Science" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="education_institution">Institution</label>
                        <input type="text" class="form-input" id="education_institution" name="education_institution" placeholder="e.g., University Name" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="education_year">Year/Duration</label>
                        <input type="text" class="form-input" id="education_year" name="education_year" placeholder="e.g., 2020-2024">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" data-modal="educationModal">Cancel</button>
                    <button type="submit" name="add_education" class="btn btn-success">Add Education</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Experience Modal -->
    <div class="modal-overlay" id="experienceModal">
        <div class="modal">
            <div class="modal-header">
                <h3>Add Work Experience</h3>
                <button class="modal-close" data-modal="experienceModal">
                    <ion-icon name="close-outline"></ion-icon>
                </button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label" for="experience_position">Position</label>
                        <input type="text" class="form-input" id="experience_position" name="experience_position" placeholder="e.g., Senior Developer" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="experience_company">Company</label>
                        <input type="text" class="form-input" id="experience_company" name="experience_company" placeholder="e.g., Tech Company Inc." required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="experience_duration">Duration</label>
                        <input type="text" class="form-input" id="experience_duration" name="experience_duration" placeholder="e.g., 2020-Present">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="experience_description">Description</label>
                        <textarea class="form-textarea" id="experience_description" name="experience_description" placeholder="Describe your responsibilities and achievements..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" data-modal="experienceModal">Cancel</button>
                    <button type="submit" name="add_experience" class="btn btn-success">Add Experience</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Project Modal -->
    <div class="modal-overlay" id="projectModal">
        <div class="modal">
            <div class="modal-header">
                <h3>Add Project</h3>
                <button class="modal-close" data-modal="projectModal">
                    <ion-icon name="close-outline"></ion-icon>
                </button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label" for="project_title">Project Title</label>
                        <input type="text" class="form-input" id="project_title" name="project_title" placeholder="e.g., E-commerce Website" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="project_description">Description</label>
                        <textarea class="form-textarea" id="project_description" name="project_description" placeholder="Describe your project..." required></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="project_url">Project URL</label>
                        <input type="url" class="form-input" id="project_url" name="project_url" placeholder="https://yourproject.com">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="project_technologies">Technologies Used</label>
                        <input type="text" class="form-input" id="project_technologies" name="project_technologies" placeholder="React, Node.js, MongoDB (comma separated)">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" data-modal="projectModal">Cancel</button>
                    <button type="submit" name="add_project" class="btn btn-success">Add Project</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Publication Modal -->
    <div class="modal-overlay" id="publicationModal">
        <div class="modal">
            <div class="modal-header">
                <h3>Add Publication</h3>
                <button class="modal-close" data-modal="publicationModal">
                    <ion-icon name="close-outline"></ion-icon>
                </button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label" for="publication_title">Title</label>
                        <input type="text" class="form-input" id="publication_title" name="publication_title" placeholder="e.g., Research Paper Title" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="publication_publisher">Publisher/Journal</label>
                        <input type="text" class="form-input" id="publication_publisher" name="publication_publisher" placeholder="e.g., Journal Name" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="publication_year">Year</label>
                        <input type="text" class="form-input" id="publication_year" name="publication_year" placeholder="e.g., 2023">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="publication_url">URL</label>
                        <input type="url" class="form-input" id="publication_url" name="publication_url" placeholder="https://link-to-publication.com">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" data-modal="publicationModal">Cancel</button>
                    <button type="submit" name="add_publication" class="btn btn-success">Add Publication</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Achievement Modal -->
    <div class="modal-overlay" id="achievementModal">
        <div class="modal">
            <div class="modal-header">
                <h3>Add Achievement</h3>
                <button class="modal-close" data-modal="achievementModal">
                    <ion-icon name="close-outline"></ion-icon>
                </button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label" for="new_achievement">Achievement</label>
                        <input type="text" class="form-input" id="new_achievement" name="new_achievement" placeholder="e.g., Hackathon Winner, Open Source Contributor" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" data-modal="achievementModal">Cancel</button>
                    <button type="submit" name="add_achievement" class="btn btn-success">Add Achievement</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Volunteer Modal -->
    <div class="modal-overlay" id="volunteerModal">
        <div class="modal">
            <div class="modal-header">
                <h3>Add Volunteer Work</h3>
                <button class="modal-close" data-modal="volunteerModal">
                    <ion-icon name="close-outline"></ion-icon>
                </button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label" for="volunteer_role">Role</label>
                        <input type="text" class="form-input" id="volunteer_role" name="volunteer_role" placeholder="e.g., Volunteer, Mentor" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="volunteer_organization">Organization</label>
                        <input type="text" class="form-input" id="volunteer_organization" name="volunteer_organization" placeholder="e.g., Non-profit Organization" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="volunteer_duration">Duration</label>
                        <input type="text" class="form-input" id="volunteer_duration" name="volunteer_duration" placeholder="e.g., 2022-Present">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="volunteer_description">Description</label>
                        <textarea class="form-textarea" id="volunteer_description" name="volunteer_description" placeholder="Describe your volunteer work..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" data-modal="volunteerModal">Cancel</button>
                    <button type="submit" name="add_volunteer" class="btn btn-success">Add Volunteer Work</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Mentorship Modal -->
    <div class="modal-overlay" id="mentorshipModal">
        <div class="modal">
            <div class="modal-header">
                <h3>Add Mentorship Area</h3>
                <button class="modal-close" data-modal="mentorshipModal">
                    <ion-icon name="close-outline"></ion-icon>
                </button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label" for="new_mentorship">Mentorship Area</label>
                        <input type="text" class="form-input" id="new_mentorship" name="new_mentorship" placeholder="e.g., Web Development, Career Advice" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" data-modal="mentorshipModal">Cancel</button>
                    <button type="submit" name="add_mentorship" class="btn btn-success">Add Mentorship Area</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Learning Goal Modal -->
    <div class="modal-overlay" id="learningGoalModal">
        <div class="modal">
            <div class="modal-header">
                <h3>Add Learning Goal</h3>
                <button class="modal-close" data-modal="learningGoalModal">
                    <ion-icon name="close-outline"></ion-icon>
                </button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label" for="learning_goal">Goal Title</label>
                        <input type="text" class="form-input" id="learning_goal" name="learning_goal" placeholder="e.g., Learn React Native" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="learning_description">Description</label>
                        <textarea class="form-textarea" id="learning_description" name="learning_description" placeholder="Describe your learning goal..."></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="learning_progress">Progress (%)</label>
                        <input type="range" class="form-input" id="learning_progress" name="learning_progress" min="0" max="100" value="0">
                        <div class="form-help" id="progress-value">0%</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" data-modal="learningGoalModal">Cancel</button>
                    <button type="submit" name="add_learning_goal" class="btn btn-success">Add Learning Goal</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Notification Modal -->
    <div class="modal-overlay" id="notificationModal">
        <div class="modal">
            <div class="modal-header">
                <h3>Notifications</h3>
                <button class="modal-close" id="modalClose">
                    <ion-icon name="close-outline"></ion-icon>
                </button>
            </div>
            <div class="modal-body">
                <?php if (count($notifications) > 0): ?>
                    <?php foreach ($notifications as $notification): ?>
                        <div class="notification-item <?php echo $notification['is_read'] ? '' : 'unread'; ?>">
                            <div class="notification-icon">
                                <i class="fas fa-bell"></i>
                            </div>
                            <div class="notification-content">
                                <div class="notification-message">
                                    <?php echo htmlspecialchars($notification['message']); ?>
                                </div>
                                <div class="notification-meta">
                                    <?php echo date('M j, Y g:i A', strtotime($notification['created_at'])); ?>
                                </div>
                                <?php if ($notification['link']): ?>
                                    <div class="notification-actions">
                                        <a href="<?php echo $notification['link']; ?>" class="notification-action">
                                            <ion-icon name="open-outline"></ion-icon>
                                            View
                                        </a>
                                        <?php if (!$notification['is_read']): ?>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="notification_id" value="<?php echo $notification['id']; ?>">
                                                <button type="submit" name="mark_as_read" class="notification-action">
                                                    Mark as read
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state" style="padding: 2rem;">
                        <i class="fas fa-bell-slash"></i>
                        <h3>No notifications</h3>
                        <p>You're all caught up!</p>
                    </div>
                <?php endif; ?>
            </div>
            <?php if (count($notifications) > 0): ?>
                <div class="modal-footer">
                    <form method="POST">
                        <button type="submit" name="mark_all_read" class="btn btn-outline btn-sm">
                            Mark all as read
                        </button>
                    </form>
                    <a href="notifications.php" class="btn btn-primary btn-sm">
                        View all notifications
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Enhanced JavaScript with fixes for all identified issues
        // Sidebar Toggle
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const sidebarToggle = document.getElementById('sidebarToggle');
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');

        sidebarToggle.addEventListener('click', () => {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
            
            // Store sidebar state in localStorage
            localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
        });

        mobileMenuBtn.addEventListener('click', () => {
            sidebar.classList.toggle('mobile-open');
        });

        // Close mobile sidebar when clicking outside
        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 768 && 
                !sidebar.contains(e.target) && 
                !mobileMenuBtn.contains(e.target)) {
                sidebar.classList.remove('mobile-open');
            }
        });

        // Restore sidebar state from localStorage
        if (localStorage.getItem('sidebarCollapsed') === 'true') {
            sidebar.classList.add('collapsed');
            mainContent.classList.add('expanded');
        }

        // Theme Toggle
        const themeToggle = document.getElementById('themeToggle');
        const themeIcon = themeToggle.querySelector('i');

        if (localStorage.getItem('theme') === 'dark' || 
            (!localStorage.getItem('theme') && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.body.classList.add('dark-mode');
            themeIcon.classList.remove('fa-moon');
            themeIcon.classList.add('fa-sun');
        }

        themeToggle.addEventListener('click', () => {
            document.body.classList.toggle('dark-mode');
            if (document.body.classList.contains('dark-mode')) {
                themeIcon.classList.remove('fa-moon');
                themeIcon.classList.add('fa-sun');
                localStorage.setItem('theme', 'dark');
            } else {
                themeIcon.classList.remove('fa-sun');
                themeIcon.classList.add('fa-moon');
                localStorage.setItem('theme', 'light');
            }
        });

        // Edit Profile Modal
        const editProfileBtn = document.getElementById('editProfileBtn');
        const editProfileModal = document.getElementById('editProfileModal');
        const editProfileClose = document.getElementById('editProfileClose');
        const cancelEdit = document.getElementById('cancelEdit');

        editProfileBtn.addEventListener('click', () => {
            editProfileModal.classList.add('active');
        });

        editProfileClose.addEventListener('click', () => {
            editProfileModal.classList.remove('active');
        });

        cancelEdit.addEventListener('click', () => {
            editProfileModal.classList.remove('active');
        });

        // Close modal when clicking outside
        editProfileModal.addEventListener('click', (e) => {
            if (e.target === editProfileModal) {
                editProfileModal.classList.remove('active');
            }
        });

        // Color Picker
        const colorOptions = document.querySelectorAll('.color-option');
        const avatarColorInput = document.getElementById('avatar_color');

        colorOptions.forEach(option => {
            option.addEventListener('click', () => {
                // Remove selected class from all options
                colorOptions.forEach(opt => opt.classList.remove('selected'));
                // Add selected class to clicked option
                option.classList.add('selected');
                // Update hidden input value
                avatarColorInput.value = option.dataset.color;
            });

            // Set initial selected color
            if (option.dataset.color === avatarColorInput.value) {
                option.classList.add('selected');
            }
        });

        // Notification Modal
        const notificationBtn = document.getElementById('notificationBtn');
        const notificationModal = document.getElementById('notificationModal');
        const modalClose = document.getElementById('modalClose');

        if (notificationBtn && notificationModal) {
            notificationBtn.addEventListener('click', () => {
                notificationModal.classList.add('active');
            });

            modalClose.addEventListener('click', () => {
                notificationModal.classList.remove('active');
            });

            // Close modal when clicking outside
            notificationModal.addEventListener('click', (e) => {
                if (e.target === notificationModal) {
                    notificationModal.classList.remove('active');
                }
            });
        }

        // Profile Tabs
        const profileTabs = document.querySelectorAll('.profile-tab');
        const tabContents = document.querySelectorAll('.tab-content');
        
        profileTabs.forEach(tab => {
            tab.addEventListener('click', () => {
                // Remove active class from all tabs and contents
                profileTabs.forEach(t => t.classList.remove('active'));
                tabContents.forEach(c => c.classList.remove('active'));
                
                // Add active class to clicked tab
                tab.classList.add('active');
                
                // Show corresponding content
                const tabId = tab.getAttribute('data-tab');
                document.getElementById(`${tabId}-tab`).classList.add('active');
            });
        });
        
        // Modal Tabs
        const modalTabs = document.querySelectorAll('.modal-tab');
        const modalTabContents = document.querySelectorAll('.modal-tab-content');
        
        modalTabs.forEach(tab => {
            tab.addEventListener('click', () => {
                // Remove active class from all modal tabs and contents
                modalTabs.forEach(t => t.classList.remove('active'));
                modalTabContents.forEach(c => c.classList.remove('active'));
                
                // Add active class to clicked tab
                tab.classList.add('active');
                
                // Show corresponding content
                const tabId = tab.getAttribute('data-modal-tab');
                document.getElementById(`${tabId}-tab`).classList.add('active');
            });
        });
        
        // Skill Level Selector
        const levelOptions = document.querySelectorAll('.level-option');
        levelOptions.forEach(option => {
            option.addEventListener('click', () => {
                // Remove selected class from all options in the same group
                const parent = option.parentElement;
                parent.querySelectorAll('.level-option').forEach(opt => {
                    opt.classList.remove('selected');
                });
                
                // Add selected class to clicked option
                option.classList.add('selected');
                
                // Update hidden input value
                const hiddenInput = parent.nextElementSibling;
                if (hiddenInput && hiddenInput.type === 'hidden') {
                    hiddenInput.value = option.getAttribute('data-level');
                }
            });
        });
        
        // Enhanced form validation
        const editProfileForm = document.getElementById('editProfileForm');
        editProfileForm.addEventListener('submit', (e) => {
            // Add any custom validation here
            console.log('Form submitted with enhanced validation');
            // If validation fails, you can call e.preventDefault()
        });
        
        // Auto-expand textareas based on content
        const textareas = document.querySelectorAll('textarea');
        textareas.forEach(textarea => {
            textarea.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = (this.scrollHeight) + 'px';
            });
            
            // Trigger initial resize
            textarea.dispatchEvent(new Event('input'));
        });

        // Add staggered animations
        document.addEventListener('DOMContentLoaded', () => {
            const cards = document.querySelectorAll('.profile-card');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
            });
            
            const statItems = document.querySelectorAll('.stat-item');
            statItems.forEach((item, index) => {
                item.style.animationDelay = `${index * 0.05}s`;
            });
        });

        // Session keep-alive
        setInterval(() => {
            fetch('profile.php').catch(err => console.log('Session refresh failed'));
        }, 30 * 60 * 1000);

        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.opacity = '0';
                setTimeout(() => {
                    alert.style.display = 'none';
                }, 300);
            });
        }, 5000);

        // Initialize skill progress bars
        document.addEventListener('DOMContentLoaded', () => {
            const skillBars = document.querySelectorAll('.skill-progress');
            skillBars.forEach(bar => {
                const width = bar.style.width;
                bar.style.width = '0';
                setTimeout(() => {
                    bar.style.width = width;
                }, 500);
            });
            
            const goalBars = document.querySelectorAll('.goal-progress-bar');
            goalBars.forEach(bar => {
                const width = bar.style.width;
                bar.style.width = '0';
                setTimeout(() => {
                    bar.style.width = width;
                }, 800);
            });
        });

        // Toast Notification
        const toast = document.getElementById('toast');
        const toastMessage = document.getElementById('toast-message');
        
        function showToast(message, type = 'success') {
            toastMessage.textContent = message;
            toast.className = 'toast';
            toast.classList.add(`toast-${type}`);
            toast.classList.add('show');
            
            setTimeout(() => {
                toast.classList.remove('show');
            }, 3000);
        }
        
        // Show success toast if section was added
        <?php if (isset($sectionSuccess) && $sectionSuccess): ?>
            setTimeout(() => {
                showToast('<?php 
                    switch($sectionType) {
                        case 'skill': echo 'Skill added successfully!'; break;
                        case 'certification': echo 'Certification added successfully!'; break;
                        case 'interest': echo 'Interest added successfully!'; break;
                        case 'hobby': echo 'Hobby added successfully!'; break;
                        case 'language': echo 'Language added successfully!'; break;
                        case 'education': echo 'Education added successfully!'; break;
                        case 'experience': echo 'Experience added successfully!'; break;
                        case 'project': echo 'Project added successfully!'; break;
                        case 'publication': echo 'Publication added successfully!'; break;
                        case 'achievement': echo 'Achievement added successfully!'; break;
                        case 'volunteer': echo 'Volunteer work added successfully!'; break;
                        case 'mentorship': echo 'Mentorship area added successfully!'; break;
                        case 'learning_goal': echo 'Learning goal added successfully!'; break;
                        default: echo 'Item added successfully!';
                    }
                ?>', 'success');
            }, 500);
        <?php endif; ?>

        // Section Add Button Handlers
        const sectionAddButtons = document.querySelectorAll('.section-add-btn');
        sectionAddButtons.forEach(button => {
            button.addEventListener('click', () => {
                const modalId = button.getAttribute('data-modal');
                const modal = document.getElementById(modalId);
                if (modal) {
                    modal.classList.add('active');
                }
            });
        });

        // Modal Close Handlers
        const modalCloseButtons = document.querySelectorAll('.modal-close');
        modalCloseButtons.forEach(button => {
            button.addEventListener('click', () => {
                const modalId = button.getAttribute('data-modal');
                const modal = document.getElementById(modalId);
                if (modal) {
                    modal.classList.remove('active');
                } else {
                    // Fallback for modals without data-modal attribute
                    const modalOverlay = button.closest('.modal-overlay');
                    if (modalOverlay) {
                        modalOverlay.classList.remove('active');
                    }
                }
            });
        });

        // Close modals when clicking outside
        const modals = document.querySelectorAll('.modal-overlay');
        modals.forEach(modal => {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    modal.classList.remove('active');
                }
            });
        });

        // Learning Goal Progress Slider
        const progressSlider = document.getElementById('learning_progress');
        const progressValue = document.getElementById('progress-value');
        
        if (progressSlider && progressValue) {
            progressSlider.addEventListener('input', () => {
                progressValue.textContent = `${progressSlider.value}%`;
            });
        }

        // Initialize all level selectors
        function initializeLevelSelectors() {
            const levelSelectors = document.querySelectorAll('.skill-level-selector');
            levelSelectors.forEach(selector => {
                const options = selector.querySelectorAll('.level-option');
                const hiddenInput = selector.nextElementSibling;
                
                if (options.length > 0 && hiddenInput && hiddenInput.type === 'hidden') {
                    // Set initial value if not already set
                    if (!hiddenInput.value) {
                        const selectedOption = selector.querySelector('.level-option.selected');
                        if (selectedOption) {
                            hiddenInput.value = selectedOption.getAttribute('data-level');
                        }
                    }
                    
                    // Add click handlers
                    options.forEach(option => {
                        option.addEventListener('click', () => {
                            options.forEach(opt => opt.classList.remove('selected'));
                            option.classList.add('selected');
                            hiddenInput.value = option.getAttribute('data-level');
                        });
                    });
                }
            });
        }

        // Initialize when DOM is loaded
        document.addEventListener('DOMContentLoaded', () => {
            initializeLevelSelectors();
            
            // Add entrance animations to cards
            const cards = document.querySelectorAll('.profile-card');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
            });
        });

        // Enhanced error handling for forms
        const forms = document.querySelectorAll('form');
        forms.forEach(form => {
            form.addEventListener('submit', function(e) {
                const requiredFields = this.querySelectorAll('[required]');
                let isValid = true;
                
                requiredFields.forEach(field => {
                    if (!field.value.trim()) {
                        isValid = false;
                        field.style.borderColor = 'var(--error)';
                        
                        // Add shake animation
                        field.classList.add('shake');
                        setTimeout(() => {
                            field.classList.remove('shake');
                        }, 500);
                    } else {
                        field.style.borderColor = '';
                    }
                });
                
                if (!isValid) {
                    e.preventDefault();
                    showToast('Please fill in all required fields', 'error');
                }
            });
        });

        // Share Profile URL Copy Function
        function copyProfileUrl() {
            const urlInput = document.getElementById("shareUrlInput");
            const copyBtn = event.currentTarget;
            
            urlInput.select();
            urlInput.setSelectionRange(0, 99999); // For mobile devices
            
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(urlInput.value).then(function() {
                    // Show success feedback
                    copyBtn.classList.add("copied");
                    copyBtn.innerHTML = '<ion-icon name="checkmark-outline"></ion-icon>';
                    
                    setTimeout(function() {
                        copyBtn.classList.remove("copied");
                        copyBtn.innerHTML = '<i class="fas fa-copy"></i>';
                    }, 2000);
                    
                    // Show toast notification
                    showToast("Profile URL copied to clipboard!", "success");
                }).catch(function(err) {
                    console.error("Failed to copy: ", err);
                    showToast("Failed to copy URL", "error");
                });
            } else {
                // Fallback for older browsers
                try {
                    document.execCommand("copy");
                    copyBtn.classList.add("copied");
                    copyBtn.innerHTML = '<ion-icon name="checkmark-outline"></ion-icon>';
                    
                    setTimeout(function() {
                        copyBtn.classList.remove("copied");
                        copyBtn.innerHTML = '<i class="fas fa-copy"></i>';
                    }, 2000);
                    
                    showToast("Profile URL copied to clipboard!", "success");
                } catch (err) {
                    console.error("Fallback copy failed: ", err);
                    showToast("Copy failed. Please copy manually.", "error");
                }
            }
        }
    </script>
</body>
</html>