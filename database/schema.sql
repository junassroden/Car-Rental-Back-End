CREATE TABLE IF NOT EXISTS migrations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    migration INT NOT NULL,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY migration_unique (migration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(100) NOT NULL,
    full_name VARCHAR(150) NULL,
    email VARCHAR(255) NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'moderator', 'user') NOT NULL DEFAULT 'user',
    is_active TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY username_unique (username),
    UNIQUE KEY email_unique (email),
    KEY email_idx (email),
    KEY role_idx (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS refresh_tokens (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    token TEXT NOT NULL,
    expires_at DATETIME NOT NULL,
    jti TEXT NOT NULL,
    PRIMARY KEY (id),
    KEY user_id_idx (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cars (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    make VARCHAR(100) NOT NULL,
    model VARCHAR(100) NOT NULL,
    year SMALLINT UNSIGNED NOT NULL,
    category VARCHAR(50) NOT NULL,
    plate_number VARCHAR(40) NULL,
    color VARCHAR(50) NULL,
    description TEXT NULL,
    daily_rental_price DECIMAL(10,2) NOT NULL,
    security_deposit DECIMAL(10,2) NOT NULL DEFAULT 0,
    minimum_rental_duration SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    transmission VARCHAR(40) NOT NULL DEFAULT 'Automatic',
    fuel_type VARCHAR(40) NOT NULL DEFAULT 'Gasoline',
    seating_capacity TINYINT UNSIGNED NOT NULL DEFAULT 5,
    air_conditioning TINYINT(1) NOT NULL DEFAULT 0,
    gps_navigation TINYINT(1) NOT NULL DEFAULT 0,
    additional_features JSON NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'available',
    primary_image VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY cars_plate_number_unique (plate_number),
    KEY cars_status_idx (status),
    KEY cars_category_idx (category),
    KEY cars_created_at_idx (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS car_images (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    car_id INT UNSIGNED NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY car_images_car_id_idx (car_id),
    CONSTRAINT car_images_car_fk
        FOREIGN KEY (car_id) REFERENCES cars(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
