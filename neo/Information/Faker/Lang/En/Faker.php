<?php

declare(strict_types=1);

/**
 * English locale data for Neo\Information\Faker.
 * The structure is the same in every locale; missing keys fall back to this file.
 */
return [
    'person' => [
        'first_names' => [
            'male' => [
                'James', 'John', 'Robert', 'Michael', 'William', 'David', 'Richard', 'Joseph', 'Thomas', 'Charles',
                'Daniel', 'Matthew', 'Anthony', 'Mark', 'Steven', 'Paul', 'Andrew', 'Joshua', 'Kevin', 'Brian',
                'George', 'Edward', 'Ronald', 'Timothy', 'Jason', 'Jeffrey', 'Ryan', 'Jacob', 'Gary', 'Nicholas',
            ],
            'female' => [
                'Mary', 'Patricia', 'Jennifer', 'Linda', 'Elizabeth', 'Barbara', 'Susan', 'Jessica', 'Sarah', 'Karen',
                'Lisa', 'Nancy', 'Betty', 'Sandra', 'Ashley', 'Emily', 'Donna', 'Michelle', 'Carol', 'Amanda',
                'Melissa', 'Deborah', 'Stephanie', 'Rebecca', 'Laura', 'Sharon', 'Cynthia', 'Kathleen', 'Amy', 'Angela',
            ],
        ],
        'last_names' => [
            'all' => [
                'Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis', 'Rodriguez', 'Martinez',
                'Hernandez', 'Lopez', 'Gonzalez', 'Wilson', 'Anderson', 'Thomas', 'Taylor', 'Moore', 'Jackson', 'Martin',
                'Lee', 'Perez', 'Thompson', 'White', 'Harris', 'Sanchez', 'Clark', 'Ramirez', 'Lewis', 'Robinson',
            ],
        ],
        'prefixes' => [
            'male' => ['Mr.', 'Dr.', 'Prof.'],
            'female' => ['Mrs.', 'Ms.', 'Miss', 'Dr.', 'Prof.'],
        ],
        'suffixes' => ['Jr.', 'Sr.', 'II', 'III', 'PhD', 'MD'],
        'name_formats' => [
            '{first} {last}', '{first} {last}', '{first} {last}', '{first} {last}', '{first} {last}', '{first} {last}',
            '{prefix} {first} {last}', '{first} {last} {suffix}',
        ],
    ],

    'internet' => [
        'free_email_domains' => ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'icloud.com'],
        'tlds' => ['com', 'net', 'org', 'io', 'dev', 'info'],
    ],

    'address' => [
        'countries' => [
            'US' => 'United States', 'GB' => 'United Kingdom', 'CA' => 'Canada', 'AU' => 'Australia', 'DE' => 'Germany',
            'FR' => 'France', 'ES' => 'Spain', 'IT' => 'Italy', 'NL' => 'Netherlands', 'PL' => 'Poland',
            'UA' => 'Ukraine', 'SE' => 'Sweden', 'NO' => 'Norway', 'JP' => 'Japan', 'BR' => 'Brazil',
            'MX' => 'Mexico', 'IN' => 'India', 'CH' => 'Switzerland', 'PT' => 'Portugal', 'IE' => 'Ireland',
        ],
        'states' => [
            'Alabama', 'Alaska', 'Arizona', 'California', 'Colorado', 'Florida', 'Georgia', 'Illinois', 'Indiana', 'Kentucky',
            'Maine', 'Michigan', 'Nevada', 'New York', 'Ohio', 'Oregon', 'Texas', 'Utah', 'Vermont', 'Washington',
        ],
        'cities' => [
            'New York', 'Los Angeles', 'Chicago', 'Houston', 'Phoenix', 'Philadelphia', 'San Antonio', 'San Diego', 'Dallas', 'Austin',
            'Seattle', 'Denver', 'Boston', 'Portland', 'Atlanta', 'Miami', 'London', 'Manchester', 'Toronto', 'Sydney',
        ],
        'street_bases' => [
            'Maple', 'Oak', 'Cedar', 'Pine', 'Elm', 'Washington', 'Lake', 'Hill', 'Park', 'Sunset',
            'River', 'Highland', 'Church', 'Main', 'Walnut', 'Spring',
        ],
        'street_types' => ['Street', 'Avenue', 'Road', 'Boulevard', 'Lane', 'Drive', 'Court', 'Way'],
        'street_formats' => ['{base} {type}'],
        'building_formats' => ['%', '%#', '%##', '%###'],
        'street_address_formats' => ['{building} {street}'],
        'postcode_formats' => ['#####', '#####-####'],
        'address_formats' => ['{street_address}, {city}, {state} {postcode}'],
    ],

    'phone' => [
        'formats' => ['(###) ###-####', '###-###-####', '###.###.####'],
        'e164_formats' => ['+1%#########'],
    ],

    'company' => [
        'suffixes' => ['Inc', 'LLC', 'Ltd', 'Group', 'Corp', 'Holdings'],
        'formats' => ['{last} {suffix}', '{last} {suffix}', '{last}-{last} {suffix}', '{last}, {last} and {last}'],
        'job_titles' => [
            'Software Engineer', 'Accountant', 'Project Manager', 'Designer', 'Teacher', 'Sales Manager', 'Data Analyst', 'Nurse',
            'Electrician', 'Marketing Specialist', 'Chef', 'Architect', 'Lawyer', 'Pharmacist', 'Mechanic',
        ],
    ],

    'color' => [
        'names' => ['red', 'orange', 'yellow', 'green', 'blue', 'indigo', 'violet', 'black', 'white', 'gray', 'pink', 'brown'],
    ],

    'datetime' => [
        'months' => [
            'January', 'February', 'March', 'April', 'May', 'June',
            'July', 'August', 'September', 'October', 'November', 'December',
        ],
        'days' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
    ],

    'text' => [
        'words' => [
            'lorem', 'ipsum', 'dolor', 'sit', 'amet', 'consectetur', 'adipiscing', 'elit', 'sed', 'do',
            'eiusmod', 'tempor', 'incididunt', 'ut', 'labore', 'et', 'dolore', 'magna', 'aliqua', 'enim',
            'ad', 'minim', 'veniam', 'quis', 'nostrud', 'exercitation', 'ullamco', 'laboris', 'nisi', 'aliquip',
            'ex', 'ea', 'commodo', 'consequat', 'duis', 'aute', 'irure', 'in', 'reprehenderit', 'voluptate',
            'velit', 'esse', 'cillum', 'fugiat', 'nulla', 'pariatur', 'excepteur', 'sint', 'occaecat', 'cupidatat',
            'non', 'proident', 'sunt', 'culpa', 'qui', 'officia', 'deserunt', 'mollit', 'anim', 'id',
        ],
    ],
];
