

INSERT INTO `students` (`student_id`, `username`, `passwd`, `f_name`, `m_name`, `l_name`, `address`, `birth_day`, `created_at`, `email`, `phone`, `verification_code`, `is_verified`) VALUES
(NULL, 'test', 'test', 'test', NULL, NULL, 'dhk', '2016-03-03', current_timestamp(), 'test@test.com', '01955994096', NULL, 1);


INSERT INTO `products` (`product_id`, `owner_id`, `product_title`, `description`, `price`, `qty` , `status`, `created_at`) VALUES 
(NULL, '1', 'Notes', 'CSE311 Notes', '500', '10' , 'available', current_timestamp());


INSERT INTO `services` (`service_id`, `student_id`, `service_title`, `description`, `price`, `created_at`) VALUES 
(NULL, '1', 'CSE311 Tutoring', 'Expert in ERD, Normalization and SQL', '1200', current_timestamp());

INSERT INTO `companies` (`company_id`, `username`, `passwd`, `name`, `address`, `created_at`, `email`, `phone`) VALUES 
(NULL, 'pathao_u', 'pathao', 'Pathao Ltd.', 'dhk', current_timestamp(), 'pathao@pathao.com', '01955994088');

INSERT INTO `jobs` (`job_id`, `company_id`, `job_title`, `description`, `salary`, `created_at`) VALUES 
(NULL, '1', 'Junior Software Engineer', 'Expirenced in Java & Python', '30000', current_timestamp());


INSERT INTO `products` (`product_id`, `owner_id`, `product_title`, `description`, `price`, `qty`, `status`, `created_at`) VALUES 
(NULL, '1', 'IC Circuits', 'Authentic products only', '50', '20', 'available', current_timestamp()),
(NULL, '1', 'Earrings (Silver)', '18K Silver Halmarked', '2000', '2', 'available', current_timestamp());

INSERT INTO `products` (`product_id`, `owner_id`, `product_title`, `description`, `price`, `qty`, `status`, `created_at`) VALUES
(NULL, '1', 'Antique Clock', 'Made in 1918', '25500', '1', 'available', current_timestamp()),
(NULL, '1', 'Cooking Set', 'The items are made in China, 100% Stainless Steel. There is a 2 year warranty with the product', '2500', '5', 'available', current_timestamp());
