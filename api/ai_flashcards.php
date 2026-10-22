<?php
/**
 * EduMate - AI Flashcards API Endpoint
 * Handles fetching curriculum subjects/chapters, retrieving cached flashcards,
 * generating new AI flashcards via Gemini API, and tracking user card mastery.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/ai_functions.php';

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

$user = current_user();
$uid  = (int)$user['id'];
$pdo  = db();

// Ensure DB Migration Tables
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_flashcards (
        id INT AUTO_INCREMENT PRIMARY KEY,
        class INT NOT NULL,
        board VARCHAR(50) NOT NULL,
        subject VARCHAR(80) NOT NULL,
        chapter_title VARCHAR(255) NOT NULL,
        front TEXT NOT NULL,
        back TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_af_lookup (class, board, subject, chapter_title(50))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_flashcard_progress (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        flashcard_id INT NOT NULL,
        status ENUM('mastered','review') NOT NULL DEFAULT 'review',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_ufp (user_id, flashcard_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

    // Clean up any previously cached dummy math fallback cards in non-math subjects
    $pdo->exec("DELETE FROM ai_flashcards WHERE front IN ('Quadratic Formula', 'Pythagoras Theorem', 'Mass-Energy Equivalence Formula') AND subject NOT IN ('Mathematics', 'Physics');");
} catch (Throwable $e) {
    error_log('Flashcard DB migration error: ' . $e->getMessage());
}

// Comprehensive Prescribed Curriculum Dataset for Classes 5 to 10
function get_prescribed_curriculum(): array {
    return [
        5 => [
            'CBSE' => [
                'Mathematics' => ['Large Numbers & Roman Numerals', 'Operations on Numbers', 'Factors & Multiples (HCF & LCM)', 'Fractions and Decimals', 'Measurement & Conversions', 'Perimeter, Area & Volume', 'Geometry: Lines & Angles', 'Data Handling & Pictographs'],
                'Science' => ['Human Body: Nervous & Skeletal Systems', 'Plant Life & Seed Germination', 'Animals & Their Adaptations', 'Food, Digestion & Deficiency Diseases', 'Air, Water & Weather', 'Matter & States of Matter', 'Force, Energy & Simple Machines'],
                'History' => ['Early Human Life & Discoveries', 'Evolution of Transport & Communication', 'Great Ancient Civilizations', 'Indian Freedom Movement Heroes'],
                'Civics' => ['Our Community & Family Rules', 'Civic Rights and Responsibilities', 'Local Governance & Public Services', 'The Indian National Flag & Anthem'],
                'Geography' => ['Globes and World Maps', 'Latitudes and Longitudes', 'Major Landforms & Water Bodies', 'Weather, Climate & Seasons', 'Natural Vegetation of India'],
                'Computer Applications' => ['Computer Fundamentals & Generations', 'Hardware & Software Components', 'Operating Systems & File Management', 'Word Processing Basics', 'Internet & Online Safety Rules'],
                'English' => ['Parts of Speech & Nouns', 'Verbs & Tenses', 'Reading Comprehension & Vocabulary', 'Paragraph & Creative Writing']
            ],
            'ICSE' => [
                'Mathematics' => ['Large Numbers & Roman Numerals', 'Operations on Numbers', 'Factors & Multiples (HCF & LCM)', 'Fractions and Decimals', 'Measurement & Conversions', 'Perimeter, Area & Volume', 'Geometry: Lines & Angles', 'Data Handling & Pictographs'],
                'Science' => ['Human Body: Nervous & Skeletal Systems', 'Plant Life & Seed Germination', 'Animals & Their Adaptations', 'Food, Digestion & Deficiency Diseases', 'Air, Water & Weather', 'Matter & States of Matter', 'Force, Energy & Simple Machines'],
                'History' => ['Early Human Life & Discoveries', 'Evolution of Transport & Communication', 'Great Ancient Civilizations', 'Indian Freedom Movement Heroes'],
                'Civics' => ['Our Community & Family Rules', 'Civic Rights and Responsibilities', 'Local Governance & Public Services', 'The Indian National Flag & Anthem'],
                'Geography' => ['Globes and World Maps', 'Latitudes and Longitudes', 'Major Landforms & Water Bodies', 'Weather, Climate & Seasons', 'Natural Vegetation of India'],
                'Computer Applications' => ['Computer Fundamentals & Generations', 'Hardware & Software Components', 'Operating Systems & File Management', 'Word Processing Basics', 'Internet & Online Safety Rules'],
                'English' => ['Parts of Speech & Nouns', 'Verbs & Tenses', 'Reading Comprehension & Vocabulary', 'Paragraph & Creative Writing']
            ],
            'State Board' => [
                'Mathematics' => ['Number Work & Roman Numerals', 'Addition & Subtraction', 'Fractions & Decimals', 'Angles & Circles', 'Pictographs'],
                'Science' => ['Our Living World', 'Water Resources', 'Food & Nutrition', 'Environmental Balance'],
                'History' => ['Early Human Life', 'Indian Freedom Movement'],
                'Civics' => ['Family & Community', 'Rules & Public Services'],
                'Geography' => ['Globes and Maps', 'India - Our Land'],
                'Computer Applications' => ['Computer Fundamentals', 'Word Processing'],
                'English' => ['Prose & Poetry', 'Nouns & Verbs', 'Sentence Construction']
            ]
        ],
        6 => [
            'CBSE' => [
                'Mathematics' => ['Knowing Our Numbers', 'Whole Numbers & Integers', 'Playing with Numbers (HCF & LCM)', 'Basic Geometrical Ideas', 'Understanding Elementary Shapes', 'Fractions and Decimals', 'Data Handling', 'Mensuration (Perimeter & Area)', 'Introduction to Algebra', 'Ratio and Proportion'],
                'Science' => ['Components of Food & Nutrition', 'Sorting & Separation of Substances', 'Getting to Know Plants', 'Body Movements & Skeleton', 'Motion and Measurement of Distances', 'Light, Shadows and Reflections', 'Electricity and Circuits', 'Fun with Magnets', 'Air and Water Around Us'],
                'History' => ['What, Where, How and When?', 'In the Earliest Cities (Indus Valley)', 'From Gathering to Growing Food', 'Kingdoms, Kings & Early Republic', 'New Empires and Kingdoms'],
                'Civics' => ['Understanding Diversity', 'Diversity and Discrimination', 'What is Government?', 'Key Elements of Democratic Government', 'Panchayati Raj & Rural Administration'],
                'Geography' => ['The Earth in the Solar System', 'Globe: Latitudes and Longitudes', 'Motions of the Earth', 'Maps & Symbols', 'Major Domains of the Earth', 'Our Country - India'],
                'Computer Applications' => ['Computer Memory & Storage Devices', 'Algorithms & Flowcharts', 'Advanced Word Processing & Formatting', 'Presentation Software Basics', 'HTML Tags & Structure', 'Cyber Ethics & Online Safety'],
                'English' => ['Nouns, Pronouns & Adjectives', 'Tenses & Subject-Verb Agreement', 'Prose & Reading Comprehension', 'Notice & Letter Writing']
            ],
            'ICSE' => [
                'Mathematics' => ['Knowing Our Numbers', 'Whole Numbers & Integers', 'Playing with Numbers (HCF & LCM)', 'Basic Geometrical Ideas', 'Understanding Elementary Shapes', 'Fractions and Decimals', 'Data Handling', 'Mensuration (Perimeter & Area)', 'Introduction to Algebra', 'Ratio and Proportion'],
                'Physics' => ['Physical Quantities & Measurement', 'Force and Pressure', 'Work and Energy', 'Light Energy', 'Electricity and Magnetism'],
                'Chemistry' => ['Introduction to Chemistry', 'Elements, Compounds & Mixtures', 'Matter & States of Matter', 'Water & Its Properties', 'Air and Atmosphere'],
                'Biology' => ['Components of Food & Nutrition', 'Plant Life & Photosynthesis', 'Cell Structure & Function', 'Human Skeleton & Movement', 'Health and Hygiene'],
                'History' => ['What, Where, How and When?', 'In the Earliest Cities (Indus Valley)', 'From Gathering to Growing Food', 'Kingdoms, Kings & Early Republic', 'New Empires and Kingdoms'],
                'Civics' => ['Understanding Diversity', 'Diversity and Discrimination', 'What is Government?', 'Key Elements of Democratic Government', 'Panchayati Raj & Rural Administration'],
                'Geography' => ['The Earth in the Solar System', 'Globe: Latitudes and Longitudes', 'Motions of the Earth', 'Maps & Symbols', 'Major Domains of the Earth', 'Our Country - India'],
                'Computer Applications' => ['Computer Memory & Storage Devices', 'Algorithms & Flowcharts', 'Advanced Word Processing & Formatting', 'Presentation Software Basics', 'HTML Tags & Structure', 'Cyber Ethics & Online Safety'],
                'English' => ['Nouns, Pronouns & Adjectives', 'Tenses & Subject-Verb Agreement', 'Prose & Reading Comprehension', 'Notice & Letter Writing']
            ],
            'State Board' => [
                'Mathematics' => ['Natural & Whole Numbers', 'Integers', 'Fractions', 'Decimals', 'Equations'],
                'Science' => ['Natural Resources', 'Diversity in Living Things', 'Substances in Daily Use', 'Force and Motion'],
                'History' => ['Our Past History', 'Ancient Civilizations'],
                'Civics' => ['Local Government Bodies', 'Civic Rights'],
                'Geography' => ['Earth & Solar System', 'Maps & Continents'],
                'Computer Applications' => ['Computer Memory & Storage', 'Presentation Software'],
                'English' => ['Prose Comprehension', 'Poetry Appreciation', 'Grammar Essentials']
            ]
        ],
        7 => [
            'CBSE' => [
                'Mathematics' => ['Integers & Operations', 'Fractions and Decimals', 'Data Handling (Mean, Median, Mode)', 'Simple Equations', 'Lines and Angles', 'The Triangle and Its Properties', 'Congruence of Triangles', 'Comparing Quantities (Percentage, Profit & Loss)', 'Rational Numbers', 'Perimeter and Area', 'Algebraic Expressions', 'Exponents and Powers'],
                'Science' => ['Nutrition in Plants & Animals', 'Heat & Temperature', 'Acids, Bases and Salts', 'Physical and Chemical Changes', 'Respiration in Organisms', 'Transportation in Animals and Plants', 'Reproduction in Plants', 'Motion and Time', 'Electric Current and Its Effects', 'Light & Reflection', 'Water: A Precious Resource'],
                'History' => ['Tracing Changes Through 1000 Years', 'New Kings & Kingdoms', 'The Delhi Sultans', 'The Mughal Empire', 'Rulers and Buildings', 'Tribes, Nomads & Settled Communities'],
                'Civics' => ['On Equality', 'Role of Government in Health', 'How the State Government Works', 'Growing up as Boys and Girls', 'Women Change the World', 'Understanding Media'],
                'Geography' => ['Environment & Ecosystem', 'Inside Our Earth (Crust, Mantle, Core)', 'Our Changing Earth & Plate Tectonics', 'Air Composition & Atmosphere', 'Water & Hydrological Cycle', 'Human Environment Settlement'],
                'Computer Applications' => ['Computer Virus & Security Threats', 'Number Systems (Binary & Decimal)', 'Spreadsheets (Excel Formulas & Charts)', 'HTML Tables, Lists & Hyperlinks', 'Introduction to Python Programming'],
                'English' => ['Adverbs & Prepositions', 'Active and Passive Voice', 'Direct and Indirect Speech', 'Prose & Poetry Analysis', 'Formal Letter & Essay Writing']
            ],
            'ICSE' => [
                'Mathematics' => ['Integers & Operations', 'Fractions and Decimals', 'Data Handling (Mean, Median, Mode)', 'Simple Equations', 'Lines and Angles', 'The Triangle and Its Properties', 'Congruence of Triangles', 'Comparing Quantities (Percentage, Profit & Loss)', 'Rational Numbers', 'Perimeter and Area', 'Algebraic Expressions', 'Exponents and Powers'],
                'Physics' => ['Physical Quantities & Measurement', 'Motion and Time', 'Heat & Temperature', 'Light Energy', 'Sound', 'Electricity'],
                'Chemistry' => ['Matter & Composition', 'Physical and Chemical Changes', 'Elements, Compounds & Mixtures', 'Atomic Structure', 'Acids, Bases and Salts'],
                'Biology' => ['Nutrition in Plants & Animals', 'Plant & Animal Tissues', 'Respiration in Organisms', 'Transportation & Circulation', 'Human Nervous System'],
                'History' => ['Tracing Changes Through 1000 Years', 'New Kings & Kingdoms', 'The Delhi Sultans', 'The Mughal Empire', 'Rulers and Buildings', 'Tribes, Nomads & Settled Communities'],
                'Civics' => ['On Equality', 'Role of Government in Health', 'How the State Government Works', 'Growing up as Boys and Girls', 'Women Change the World', 'Understanding Media'],
                'Geography' => ['Environment & Ecosystem', 'Inside Our Earth (Crust, Mantle, Core)', 'Our Changing Earth & Plate Tectonics', 'Air Composition & Atmosphere', 'Water & Hydrological Cycle', 'Human Environment Settlement'],
                'Computer Applications' => ['Computer Virus & Security Threats', 'Number Systems (Binary & Decimal)', 'Spreadsheets (Excel Formulas & Charts)', 'HTML Tables, Lists & Hyperlinks', 'Introduction to Python Programming'],
                'English' => ['Adverbs & Prepositions', 'Active and Passive Voice', 'Direct and Indirect Speech', 'Prose & Poetry Analysis', 'Formal Letter & Essay Writing']
            ],
            'State Board' => [
                'Mathematics' => ['Geometrical Constructions', 'Integers', 'Indices', 'Direct & Inverse Proportion', 'Pythagoras Theorem'],
                'Science' => ['Adaptation in Living Things', 'Plants Structure & Function', 'Static Electricity', 'Heat and Temperature', 'Sound'],
                'History' => ['India in Medieval Times', 'Delhi Sultans & Mughals'],
                'Civics' => ['State Government', 'Fundamental Rights'],
                'Geography' => ['Earth Layers & Atmosphere', 'Weather & Climate'],
                'Computer Applications' => ['Spreadsheets & Formulas', 'HTML Basics'],
                'English' => ['Prose and Poetry', 'Grammar & Transformation']
            ]
        ],
        8 => [
            'CBSE' => [
                'Mathematics' => ['Rational Numbers', 'Linear Equations in One Variable', 'Understanding Quadrilaterals', 'Data Handling & Probability', 'Squares, Square Roots, Cubes & Cube Roots', 'Comparing Quantities (Compound Interest)', 'Algebraic Expressions & Factorisation', 'Mensuration (Area & Volume)', 'Exponents and Powers', 'Direct and Inverse Proportions'],
                'Science' => ['Crop Production and Management', 'Microorganisms: Friend and Foe', 'Coal, Petroleum & Combustion', 'Conservation of Plants and Animals', 'Cell - Structure and Functions', 'Reproduction in Animals & Adolescence', 'Force and Pressure', 'Friction & Sound', 'Chemical Effects of Electric Current', 'Light & Vision', 'Natural Phenomena (Lightning & Earthquakes)'],
                'History' => ['How, When and Where (Modern History)', 'From Trade to Territory (British Rule)', 'Ruling the Countryside', 'Tribals, Dikus & Golden Age', 'The Revolt of 1857', 'Civilising the Native & Educating Nation', 'Women, Caste and Reform', 'The Making of National Movement'],
                'Civics' => ['The Indian Constitution', 'Understanding Secularism', 'Why Do We Need a Parliament?', 'Understanding Laws', 'Judiciary & Criminal Justice System', 'Understanding Marginalisation', 'Confronting Marginalisation', 'Public Facilities & Law'],
                'Geography' => ['Resources: Types & Conservation', 'Land, Soil, Water & Wildlife Resources', 'Mineral and Power Resources', 'Agriculture & Farming Types', 'Industries & Location Factors', 'Human Resources & Population'],
                'Computer Applications' => ['Computer Networking & Topologies', 'Database Management Systems (DBMS Basics)', 'HTML Forms & CSS Styling', 'Python Control Flow (If-Else & Loops)', 'Artificial Intelligence & Cloud Computing'],
                'English' => ['Tenses & Modal Auxiliaries', 'Clauses & Sentence Transformation', 'Reported Speech', 'Prose & Poetry Comprehension', 'Article & Analytical Writing']
            ],
            'ICSE' => [
                'Mathematics' => ['Rational Numbers', 'Linear Equations in One Variable', 'Understanding Quadrilaterals', 'Data Handling & Probability', 'Squares, Square Roots, Cubes & Cube Roots', 'Comparing Quantities (Compound Interest)', 'Algebraic Expressions & Factorisation', 'Mensuration (Area & Volume)', 'Exponents and Powers', 'Direct and Inverse Proportions'],
                'Physics' => ['Matter and Kinetic Theory', 'Pressure in Fluids & Atmosphere', 'Force and Motion', 'Heat Transfer & Calorimetry', 'Sound Waves', 'Light Energy & Spherical Mirrors', 'Electricity & Magnetism'],
                'Chemistry' => ['Atomic Structure & Chemical Bonding', 'Chemical Reactions & Equations', 'Study of Hydrogen', 'Water & Hardness', 'Carbon and Its Compounds'],
                'Biology' => ['Crop Production & Microorganisms', 'Cell - Structure and Functions', 'Transport in Plants & Animals', 'Reproduction in Plants & Animals', 'Endocrine & Nervous System'],
                'History' => ['How, When and Where (Modern History)', 'From Trade to Territory (British Rule)', 'Ruling the Countryside', 'Tribals, Dikus & Golden Age', 'The Revolt of 1857', 'Civilising the Native & Educating Nation', 'Women, Caste and Reform', 'The Making of National Movement'],
                'Civics' => ['The Indian Constitution', 'Understanding Secularism', 'Why Do We Need a Parliament?', 'Understanding Laws', 'Judiciary & Criminal Justice System', 'Understanding Marginalisation', 'Confronting Marginalisation', 'Public Facilities & Law'],
                'Geography' => ['Resources: Types & Conservation', 'Land, Soil, Water & Wildlife Resources', 'Mineral and Power Resources', 'Agriculture & Farming Types', 'Industries & Location Factors', 'Human Resources & Population'],
                'Computer Applications' => ['Computer Networking & Topologies', 'Database Management Systems (DBMS Basics)', 'HTML Forms & CSS Styling', 'Python Control Flow (If-Else & Loops)', 'Artificial Intelligence & Cloud Computing'],
                'English' => ['Tenses & Modal Auxiliaries', 'Clauses & Sentence Transformation', 'Reported Speech', 'Prose & Poetry Comprehension', 'Article & Analytical Writing']
            ],
            'State Board' => [
                'Mathematics' => ['Rational & Irrational Numbers', 'Parallel Lines', 'Indices & Cube Root', 'Altitudes of Triangle', 'Expansion Formulae'],
                'Science' => ['Living World and Microbes', 'Health and Diseases', 'Force and Pressure', 'Current Electricity', 'Measurement of Matter'],
                'History' => ['Sources of History', 'Modern India & British Rule', 'Revolt of 1857'],
                'Civics' => ['Indian Constitution', 'Parliament & Judiciary', 'Human Rights'],
                'Geography' => ['Land & Mineral Resources', 'Agriculture & Industries'],
                'Computer Applications' => ['Database Basics', 'Networking & Python Control Flow'],
                'English' => ['Prose Readings', 'Poetic Devices', 'Writing Skills']
            ]
        ],
        9 => [
            'CBSE' => [
                'Mathematics' => ['Number Systems', 'Polynomials', 'Coordinate Geometry', 'Linear Equations in Two Variables', 'Introduction to Euclid\'s Geometry', 'Lines and Angles', 'Triangles', 'Quadrilaterals', 'Circles', 'Heron\'s Formula', 'Surface Areas and Volumes', 'Statistics'],
                'Science' => ['Matter in Our Surroundings', 'Is Matter Around Us Pure', 'Atoms and Molecules', 'Structure of the Atom', 'The Fundamental Unit of Life (Cell)', 'Tissues', 'Motion', 'Force and Laws of Motion', 'Gravitation', 'Work and Energy', 'Sound', 'Improvement in Food Resources'],
                'History' => ['The French Revolution', 'Socialism in Europe and the Russian Revolution', 'Nazism and the Rise of Hitler', 'Forest Society and Colonialism', 'Pastoralists in the Modern World'],
                'Civics' => ['What is Democracy? Why Democracy?', 'Constitutional Design', 'Electoral Politics', 'Working of Institutions', 'Democratic Rights'],
                'Geography' => ['India - Size and Location', 'Physical Features of India', 'Drainage Systems (Rivers)', 'Climate & Monsoons', 'Natural Vegetation & Wildlife', 'Population Dynamics'],
                'Computer Applications' => ['Computer Systems & Architecture', 'Cyber Safety & Digital Ethics', 'HTML5 & CSS3 Responsive Web Design', 'Python Variables, Data Types & Operators', 'Python Loops, Conditional Statements & Functions'],
                'English' => ['The Fun They Had', 'The Sound of Music', 'The Little Girl', 'A Truly Beautiful Mind', 'The Snake and the Mirror', 'My Childhood', 'Reach for the Top']
            ],
            'ICSE' => [
                'Mathematics' => ['Rational and Irrational Numbers', 'Compound Interest', 'Expansions', 'Factorisation', 'Simultaneous Equations', 'Triangles', 'Mid-Point Theorem', 'Pythagoras Theorem', 'Rectilinear Figures', 'Coordinate Geometry', 'Mensuration', 'Statistics'],
                'Physics' => ['Measurements and Experimentation', 'Motion in One Dimension', 'Laws of Motion', 'Fluids', 'Heat and Energy', 'Light', 'Sound', 'Electricity and Magnetism'],
                'Chemistry' => ['Language of Chemistry', 'Chemical Changes and Reactions', 'Water', 'Atomic Structure and Chemical Bonding', 'The Periodic Table', 'Study of Hydrogen', 'Atmospheric Pollution'],
                'Biology' => ['Cell: The Unit of Life', 'Tissues: Plant and Animal Tissues', 'The Flower', 'Pollination and Fertilisation', 'Seeds', 'Respiration in Plants', 'Diversity in Living Organisms', 'Nutrition and Digestive System', 'Movement and Locomotion'],
                'History' => ['The French Revolution', 'Socialism in Europe and the Russian Revolution', 'Nazism and the Rise of Hitler', 'Forest Society and Colonialism', 'Pastoralists in the Modern World'],
                'Civics' => ['What is Democracy? Why Democracy?', 'Constitutional Design', 'Electoral Politics', 'Working of Institutions', 'Democratic Rights'],
                'Geography' => ['India - Size and Location', 'Physical Features of India', 'Drainage Systems (Rivers)', 'Climate & Monsoons', 'Natural Vegetation & Wildlife', 'Population Dynamics'],
                'Computer Applications' => ['Computer Systems & Architecture', 'Cyber Safety & Digital Ethics', 'HTML5 & CSS3 Responsive Web Design', 'Python Variables, Data Types & Operators', 'Python Loops, Conditional Statements & Functions'],
                'English' => ['Drama & Plays', 'Poetry Analysis', 'Comprehension & Essay']
            ],
            'State Board' => [
                'Mathematics' => ['Sets', 'Real Numbers', 'Polynomials', 'Ratio and Proportion', 'Linear Equations in Two Variables', 'Triangles', 'Coordinate Geometry', 'Trigonometry', 'Circle'],
                'Science' => ['Laws of Motion', 'Work and Energy', 'Current Electricity', 'Measurement of Matter', 'Acids Bases and Salts', 'Classification of Plants', 'Useful and Harmful Microbes', 'Energy Flow in Ecosystem'],
                'History' => ['India: Internal Challenges', 'World after Second World War'],
                'Civics' => ['Political System', 'Working of Institutions'],
                'Geography' => ['Physical Features', 'Climate & Drainage'],
                'Computer Applications' => ['Computer Systems', 'HTML5 Web Design & Python'],
                'English' => ['Prose Anthology', 'Poetic Forms', 'Advanced English Composition']
            ]
        ],
        10 => [
            'CBSE' => [
                'Mathematics' => ['Real Numbers', 'Polynomials', 'Pair of Linear Equations in Two Variables', 'Quadratic Equations', 'Arithmetic Progressions', 'Triangles', 'Coordinate Geometry', 'Introduction to Trigonometry', 'Some Applications of Trigonometry', 'Circles', 'Areas Related to Circles', 'Surface Areas and Volumes', 'Statistics', 'Probability'],
                'Science' => ['Chemical Reactions and Equations', 'Acids Bases and Salts', 'Metals and Non-Metals', 'Carbon and Its Compounds', 'Life Processes', 'Control and Coordination', 'How do Organisms Reproduce?', 'Heredity and Evolution', 'Light - Reflection and Refraction', 'The Human Eye and the Colourful World', 'Electricity', 'Magnetic Effects of Electric Current', 'Our Environment'],
                'History' => ['The Rise of Nationalism in Europe', 'Nationalism in India', 'The Making of a Global World', 'The Age of Industrialisation', 'Print Culture and the Modern World'],
                'Civics' => ['Power Sharing', 'Federalism', 'Gender, Religion and Caste', 'Political Parties', 'Outcomes of Democracy'],
                'Geography' => ['Resources and Development', 'Forest and Wildlife Resources', 'Water Resources', 'Agriculture', 'Minerals and Energy Resources', 'Manufacturing Industries', 'Lifelines of National Economy'],
                'Computer Applications' => ['Internet Services & Protocols (HTTP, DNS, FTP)', 'HTML5 Web Forms, Tables & CSS Styling', 'Cyber Ethics, Intellectual Property & Netiquette', 'Object-Oriented Programming (Java / Python Classes)', 'Database SQL Queries & Database Management'],
                'English' => ['A Letter to God', 'Nelson Mandela: Long Walk to Freedom', 'Two Stories about Flying', 'From the Diary of Anne Frank', 'Glimpses of India', 'Madam Rides the Bus', 'The Sermon at Benares']
            ],
            'ICSE' => [
                'Mathematics' => ['Commercial Mathematics (GST, Banking)', 'Algebra (Linear Inequations, Quadratic Equations)', 'Geometry (Similarity, Circles)', 'Mensuration', 'Trigonometry', 'Statistics & Probability'],
                'Physics' => ['Force Work Power Energy', 'Refraction of Light', 'Spectrum', 'Sound', 'Current Electricity', 'Household Circuits', 'Electro-Magnetism', 'Calorimetry', 'Radioactivity'],
                'Chemistry' => ['Periodic Properties', 'Chemical Bonding', 'Acids Bases and Salts', 'Analytical Chemistry', 'Mole Concept & Stoichiometry', 'Electrolysis', 'Metallurgy', 'Study of Compounds', 'Organic Chemistry'],
                'Biology' => ['Cell Cycle & Cell Division', 'Structure of Chromosomes', 'Genetics', 'Absorption by Roots', 'Transpiration', 'Photosynthesis', 'Circulatory System', 'Excretory System', 'Nervous System', 'Endocrine System'],
                'History' => ['The Rise of Nationalism in Europe', 'Nationalism in India', 'The Making of a Global World', 'The Age of Industrialisation', 'Print Culture and the Modern World'],
                'Civics' => ['Power Sharing', 'Federalism', 'Gender, Religion and Caste', 'Political Parties', 'Outcomes of Democracy'],
                'Geography' => ['Resources and Development', 'Forest and Wildlife Resources', 'Water Resources', 'Agriculture', 'Minerals and Energy Resources', 'Manufacturing Industries', 'Lifelines of National Economy'],
                'Computer Applications' => ['Internet Services & Protocols (HTTP, DNS, FTP)', 'HTML5 Web Forms, Tables & CSS Styling', 'Cyber Ethics, Intellectual Property & Netiquette', 'Object-Oriented Programming (Java / Python Classes)', 'Database SQL Queries & Database Management'],
                'English' => ['Shakespeare Plays', 'Poetry Collection', 'Formal Essays']
            ],
            'State Board' => [
                'Mathematics' => ['Linear Equations in Two Variables', 'Quadratic Equations', 'Arithmetic Progression', 'Financial Planning', 'Probability', 'Statistics', 'Similarity', 'Pythagoras Theorem', 'Circle', 'Geometric Constructions', 'Coordinate Geometry', 'Trigonometry', 'Mensuration'],
                'Science' => ['Gravitation', 'Periodic Classification of Elements', 'Chemical Reactions and Equations', 'Effects of Electric Current', 'Heat', 'Refraction of Light', 'Lenses', 'Metallurgy', 'Carbon Compounds', 'Space Missions', 'Heredity & Evolution', 'Life Processes', 'Environmental Management'],
                'History' => ['Historiography & Indian History', 'Mass Media and History'],
                'Civics' => ['Working of the Constitution', 'Electoral Process & Political Parties'],
                'Geography' => ['Location & Extent', 'Physiography, Climate & Vegetation'],
                'English' => ['Prose Masterpieces', 'Poetic Study', 'Exam Grammar & Composition']
            ]
        ]
    ];
}





/**
 * Generates 12-15 high-quality AI revision flashcards live via Generative AI (Gemini 2.5 Pro).
 */
function generate_ai_flashcard_deck(PDO $pdo, int $class, string $board, string $subject, string $chapter, bool $forceRegenerate = false, int $uid = 0): array {
    // 1. Delete old cards if forceRegenerate is true
    if ($forceRegenerate) {
        $del = $pdo->prepare('DELETE FROM ai_flashcards WHERE class = :cl AND board = :bo AND subject = :sub AND chapter_title = :chap');
        $del->execute([':cl' => $class, ':bo' => $board, ':sub' => $subject, ':chap' => $chapter]);
    } else {
        // Check if cards already exist
        $stmt = $pdo->prepare('SELECT f.id, f.front, f.back, COALESCE(fp.status, "review") AS user_status 
                               FROM ai_flashcards f
                          LEFT JOIN ai_flashcard_progress fp ON fp.flashcard_id = f.id AND fp.user_id = :u
                              WHERE f.class = :cl AND f.board = :bo AND f.subject = :sub AND f.chapter_title = :chap
                           ORDER BY f.id ASC');
        $stmt->execute([
            ':u' => $uid,
            ':cl' => $class,
            ':bo' => $board,
            ':sub' => $subject,
            ':chap' => $chapter
        ]);
        $existing = $stmt->fetchAll();
        if (!empty($existing)) {
            return $existing;
        }
    }

    // 2. Comprehensive Generative AI Prompt for 15 high-standard board-exam flashcards
    $prompt = "Act as a Senior Board Paper Setter and Chief Academic Director for Class {$class} ({$board} Board).
Generate a top-standard, academically challenging, exam-oriented revision flashcard deck of 15 high-rigor questions and precise answer keys STRICTLY for:
- Subject: '{$subject}'
- Chapter Title: '{$chapter}'

ELEVATED ACADEMIC RIGOR REQUIREMENTS:
1. HIGH QUESTION STANDARD (HOTS): Formulate challenging questions that test conceptual depth, analytical reasoning, numerical calculations, chemical stoichiometry, or historical cause-and-effect. Avoid basic 1-word or trivial definitions.
2. 100% CHAPTER SPECIFIC: Every single question MUST strictly belong to '{$chapter}'.
3. DECK COMPOSITION (15 Cards):
   - 4 Analytical Reasoning & Mechanism Questions (\"Explain why...\", \"How does the mechanism of... work?\")
   - 3 Quantitative Numerical Problems / Balanced Chemical Equations / Code Logic belonging to '{$chapter}'
   - 3 Comparative & Differentiating Questions (\"Distinguish between X and Y based on...\")
   - 3 Laws, Theorems, & Core Scientific/Historical Concepts of '{$chapter}'
   - 2 Real-World Application & Case-Scenario Problems
4. FRONT OF CARD: Precise, challenging, board-exam standard question or numerical problem.
5. BACK OF CARD: Ultra-crisp, factual answer key (15-30 words max). State direct values, formulas, equations, or facts without generic filler commentary.
6. FORMULA & EQUATION RULE: Wrap any mathematical formula, physical equation, or chemical reaction from '{$chapter}' inside compact LaTeX `<div class=\"equation-box\">$$...$$</div>`.

Format EXACTLY as a JSON array wrapped in a code block:
```json
[
  {
    \"front\": \"High-rigor question from '{$chapter}'\",
    \"back\": \"Exact factual answer key...\"
  }
]
```
Do not output any conversational text before or after the JSON block.";

    $messages = [['role' => 'user', 'parts' => [['text' => $prompt]]]];
    $aiRaw = call_gemini_api($messages, defined('AI_FLASHCARD_SYSTEM_PROMPT') ? AI_FLASHCARD_SYSTEM_PROMPT : null);

    $jsonStr = '';
    if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/i', $aiRaw, $m)) {
        $jsonStr = trim($m[1]);
    } elseif (preg_match('/(\[\s*\{[\s\S]*\}\s*\])/', $aiRaw, $m)) {
        $jsonStr = trim($m[1]);
    }

    // Attempt 1: Standard JSON decode
    $cardsData = json_decode($jsonStr, true);
    
    // Attempt 2: Escape single LaTeX backslashes for valid JSON
    if (!is_array($cardsData) || empty($cardsData)) {
        $jsonStrFixed = preg_replace('/\\\\([a-zA-Z0-9_\+\-\*\^\=\(\)\[\]\{\}\<\>\!\,\.\:\;\/]+)/', '\\\\\\\\$1', $jsonStr);
        $cardsData = json_decode($jsonStrFixed, true);
    }

    // Require pure Generative AI response directly from Gemini API
    if (!is_array($cardsData) || empty($cardsData)) {
        throw new Exception("Generative AI Engine Message: " . strip_tags($aiRaw));
    }

    // Insert generated cards into DB
    $ins = $pdo->prepare('INSERT INTO ai_flashcards (class, board, subject, chapter_title, front, back) VALUES (:cl, :bo, :sub, :chap, :f, :b)');
    $newCards = [];
    foreach ($cardsData as $c) {
        $front = trim((string)($c['front'] ?? "Concept Question"));
        $back  = trim((string)($c['back'] ?? "Explanation"));
        $ins->execute([
            ':cl' => $class,
            ':bo' => $board,
            ':sub' => $subject,
            ':chap' => $chapter,
            ':f' => $front,
            ':b' => $back
        ]);
        $newCards[] = [
            'id' => (int)$pdo->lastInsertId(),
            'front' => $front,
            'back' => $back,
            'user_status' => 'review'
        ];
    }

    if ($uid > 0 && function_exists('record_activity')) {
        record_activity($uid, 'fa-robot', "Generated AI Flashcards for Class {$class} {$board} {$subject} - {$chapter}", null);
    }

    return $newCards;
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_REQUEST;
$action = $input['action'] ?? '';

try {
    // Action 1: Get Prescribed Curriculum Structure based on Profile Settings
    if ($action === 'get_curriculum') {
        $uStmt = $pdo->prepare('SELECT class, board FROM users WHERE id = :id');
        $uStmt->execute([':id' => $uid]);
        $uProfile = $uStmt->fetch();

        $userClass = (int)($uProfile['class'] ?? $user['class'] ?? 8);
        $rawBoard  = trim((string)($uProfile['board'] ?? $user['board'] ?? 'CBSE'));

        if ($userClass < 5 || $userClass > 10) $userClass = 8;
        
        // Normalize Board string
        if (stripos($rawBoard, 'icse') !== false) {
            $userBoard = 'ICSE';
        } elseif (stripos($rawBoard, 'state') !== false) {
            $userBoard = 'State Board';
        } else {
            $userBoard = 'CBSE';
        }

        $curriculum = get_prescribed_curriculum();
        $profileCurriculum = $curriculum[$userClass][$userBoard] ?? $curriculum[8]['CBSE'];

        echo json_encode([
            'userClass' => $userClass,
            'userBoard' => $userBoard,
            'availableClasses' => [5, 6, 7, 8, 9, 10],
            'availableBoards' => ['CBSE', 'ICSE', 'State Board'],
            'profileCurriculum' => $profileCurriculum,
            'fullCurriculum' => $curriculum
        ]);
        exit;
    }

    // Action 2: Get or Generate Flashcards for a Chapter
    if ($action === 'get_cards') {
        $class = (int)($input['class'] ?? $user['class'] ?? 8);
        $board = trim((string)($input['board'] ?? $user['board'] ?? 'CBSE'));
        $subject = trim((string)($input['subject'] ?? ''));
        $chapter = trim((string)($input['chapter'] ?? ''));
        $forceRegenerate = !empty($input['regenerate']);

        if ($subject === '' || $chapter === '') {
            throw new Exception('Subject and Chapter are required.');
        }

        $cards = generate_ai_flashcard_deck($pdo, $class, $board, $subject, $chapter, $forceRegenerate, $uid);
        echo json_encode(['status' => 'success', 'cards' => $cards]);
        exit;
    }

    // Action 3: Toggle Mastery Status
    if ($action === 'toggle_mastery') {
        $cardId = (int)($input['card_id'] ?? 0);
        $status = ($input['status'] ?? 'review') === 'mastered' ? 'mastered' : 'review';

        if ($cardId <= 0) throw new Exception('Invalid card ID');

        $stmt = $pdo->prepare('INSERT INTO ai_flashcard_progress (user_id, flashcard_id, status) 
                               VALUES (:u, :fc, :st) 
                               ON DUPLICATE KEY UPDATE status = :st2');
        $stmt->execute([':u' => $uid, ':fc' => $cardId, ':st' => $status, ':st2' => $status]);

        echo json_encode(['status' => 'success', 'card_id' => $cardId, 'new_status' => $status]);
        exit;
    }

    echo json_encode(['error' => 'Invalid action']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
