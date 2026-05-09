CREATE TABLE students(
	student_id INT(11) UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(255) UNIQUE NOT NULL,
    passwd VARCHAR(255) NOT NULL,
    f_name VARCHAR(255) NOT NULL,
    m_name VARCHAR(255),
    l_name VARCHAR(255),
    address VARCHAR(255) NOT NULL,
    birth_day DATE NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    email VARCHAR(255) UNIQUE NOT NULL,
    phone VARCHAR(20),
    verification_code VARCHAR(6) DEFAULT NULL,
    is_verified TINYINT(1) NOT NULL DEFAULT 0
);

-- Migration for existing databases:
-- ALTER TABLE students
-- ADD COLUMN verification_code VARCHAR(6) DEFAULT NULL,
-- ADD COLUMN is_verified TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE services (
    service_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    service_title VARCHAR(255) NOT NULL,
    description TEXT,
    price DECIMAL(10,2),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (student_id) REFERENCES students(student_id)
        ON DELETE CASCADE
);

CREATE TABLE req_service (
    request_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    
    service_id INT UNSIGNED NOT NULL,
    requester_id INT UNSIGNED NOT NULL,
    
    status ENUM('pending', 'accepted', 'rejected', 'completed') NOT NULL DEFAULT 'pending',
    
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (service_id) REFERENCES services(service_id)
        ON DELETE CASCADE,
        
    FOREIGN KEY (requester_id) REFERENCES students(student_id)
        ON DELETE CASCADE
);

CREATE TABLE products (
    product_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_id INT UNSIGNED NOT NULL,
    product_title VARCHAR(255) NOT NULL,
    description TEXT,
    price DECIMAL(10,2),
    qty INT(6),
    status ENUM('available', 'sold') NOT NULL DEFAULT 'available',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (owner_id) REFERENCES students(student_id)
        ON DELETE CASCADE
);

CREATE TABLE buy_product (
    buy_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    
    product_id INT UNSIGNED NOT NULL,
    buyer_id INT UNSIGNED NOT NULL,
    qty INT(6),
    bought_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (product_id) REFERENCES products(product_id)
        ON DELETE CASCADE,
        
    FOREIGN KEY (buyer_id) REFERENCES students(student_id)
        ON DELETE CASCADE
);


CREATE TABLE companies (
	company_id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(255) UNIQUE NOT NULL,
    passwd VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL,
    address VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    email VARCHAR(255) UNIQUE NOT NULL,
    phone VARCHAR(20)
);

CREATE TABLE jobs (
    job_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    job_title VARCHAR(255) NOT NULL,
    description TEXT,
    salary DECIMAL(10,2),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (company_id) REFERENCES companies(company_id)
        ON DELETE CASCADE
);

CREATE TABLE apply_job (
    application_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    
    job_id INT UNSIGNED NOT NULL,
    applicant_id INT UNSIGNED NOT NULL,
    
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (job_id) REFERENCES jobs(job_id)
        ON DELETE CASCADE,
        
    FOREIGN KEY (applicant_id) REFERENCES students(student_id)
        ON DELETE CASCADE
);


CREATE TABLE reviews (
    review_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    
    reviewer_id INT UNSIGNED NOT NULL,
    
    service_id INT UNSIGNED,
    product_id INT UNSIGNED,
    company_id INT UNSIGNED,
    
    rating INT NOT NULL CHECK (rating BETWEEN 1 AND 5),
    comment TEXT,
    
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (reviewer_id) REFERENCES students(student_id)
        ON DELETE CASCADE,
        
    FOREIGN KEY (service_id) REFERENCES services(service_id)
        ON DELETE CASCADE,
        
    FOREIGN KEY (product_id) REFERENCES products(product_id)
        ON DELETE CASCADE,
        
    FOREIGN KEY (company_id) REFERENCES companies(company_id)
        ON DELETE CASCADE,
        
    CHECK (
        (service_id IS NOT NULL AND product_id IS NULL AND company_id IS NULL)
        OR
        (service_id IS NULL AND product_id IS NOT NULL AND company_id IS NULL)
        OR
        (service_id IS NULL AND product_id IS NULL AND company_id IS NOT NULL)
    )
);
