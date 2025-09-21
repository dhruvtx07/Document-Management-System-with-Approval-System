-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jun 01, 2025 at 10:08 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `doc_mg`
--

-- --------------------------------------------------------

--
-- Table structure for table `admin`
--

CREATE TABLE `admin` (
  `admin_id` int(11) NOT NULL,
  `Clg_id` int(11) NOT NULL,
  `Uid` int(11) NOT NULL,
  `Created_by` int(11) NOT NULL,
  `Created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `Is_active` enum('y','n') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admin`
--

INSERT INTO `admin` (`admin_id`, `Clg_id`, `Uid`, `Created_by`, `Created_at`, `Is_active`) VALUES
(1, 1, 1, 1, '2025-05-27 23:36:22', 'y');

-- --------------------------------------------------------

--
-- Table structure for table `caste`
--

CREATE TABLE `caste` (
  `Caste_id` int(11) NOT NULL,
  `Clg_id` int(11) NOT NULL,
  `Caste_name` varchar(255) NOT NULL,
  `Created_by` int(11) NOT NULL,
  `Created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `Is_active` enum('y','n') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `caste`
--

INSERT INTO `caste` (`Caste_id`, `Clg_id`, `Caste_name`, `Created_by`, `Created_at`, `Is_active`) VALUES
(1, 1, 'GENERAL', 1, '2025-05-28 19:47:27', 'y'),
(2, 1, 'SC', 1, '2025-05-28 19:47:42', 'y'),
(3, 1, 'ST', 1, '2025-05-28 19:47:53', 'y'),
(4, 1, 'OBC', 1, '2025-05-28 19:48:02', 'y');

-- --------------------------------------------------------

--
-- Table structure for table `classes`
--

CREATE TABLE `classes` (
  `Clg_id` int(11) NOT NULL,
  `Class_id` int(11) NOT NULL,
  `Class_name` varchar(255) NOT NULL,
  `Semester` int(11) NOT NULL,
  `Session` varchar(50) NOT NULL,
  `Created_by` int(11) NOT NULL,
  `Created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `Is_active` enum('y','n') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `classes`
--

INSERT INTO `classes` (`Clg_id`, `Class_id`, `Class_name`, `Semester`, `Session`, `Created_by`, `Created_at`, `Is_active`) VALUES
(1, 1, 'MCA', 4, '2023-2025', 1, '2025-05-28 19:43:47', 'y'),
(1, 2, 'BCA', 1, '2025-2028', 1, '2025-06-01 03:26:29', 'y');

-- --------------------------------------------------------

--
-- Table structure for table `clgs`
--

CREATE TABLE `clgs` (
  `Clg_id` int(11) NOT NULL,
  `Clg_name` varchar(255) NOT NULL,
  `Clg_adress` text NOT NULL,
  `Clg_city` varchar(100) NOT NULL,
  `Clg_state` varchar(100) NOT NULL,
  `Clg_country` varchar(100) NOT NULL,
  `Created_by` int(11) NOT NULL,
  `User_count` int(11) NOT NULL DEFAULT 0,
  `Admin_count` int(11) NOT NULL DEFAULT 0,
  `Teacher_count` int(11) NOT NULL DEFAULT 0,
  `Student_count` int(11) NOT NULL DEFAULT 0,
  `Created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `Is_active` enum('y','n') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `clgs`
--

INSERT INTO `clgs` (`Clg_id`, `Clg_name`, `Clg_adress`, `Clg_city`, `Clg_state`, `Clg_country`, `Created_by`, `User_count`, `Admin_count`, `Teacher_count`, `Student_count`, `Created_at`, `Is_active`) VALUES
(1, 'GGNIMT', 'Ghumar Mandi Rd, Mall Enclave, Civil Lines, Ludhiana, Punjab 141001', 'Ludhiana', 'Punjab', 'India', 1, 1, 1, 0, 0, '2025-05-27 23:33:11', 'y');

-- --------------------------------------------------------

--
-- Table structure for table `doc`
--

CREATE TABLE `doc` (
  `Clg_id` int(11) NOT NULL,
  `Doc_id` int(11) NOT NULL,
  `Doc_name` varchar(255) NOT NULL,
  `Doc_desc` text NOT NULL,
  `Doc_type` varchar(100) NOT NULL,
  `Doc_ext` varchar(20) NOT NULL,
  `Doc_max_size` int(11) NOT NULL,
  `Is_active` enum('y','n') NOT NULL,
  `Created_by` int(11) NOT NULL,
  `Created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `folder`
--

CREATE TABLE `folder` (
  `Clg_id` int(11) NOT NULL,
  `Folder_id` int(11) NOT NULL,
  `Folder_name` varchar(255) NOT NULL,
  `folder_desc` text NOT NULL,
  `folder_type` varchar(100) NOT NULL,
  `Doc_ext` varchar(20) NOT NULL,
  `Doc_max_size` int(11) NOT NULL,
  `Created_by` int(11) NOT NULL,
  `Created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `Is_active` enum('y','n') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `folder_docs_submitted_students`
--

CREATE TABLE `folder_docs_submitted_students` (
  `folder_doc_submission_id` int(11) NOT NULL,
  `Clg_id` int(11) NOT NULL,
  `Class_id` int(11) DEFAULT NULL,
  `Student_id` int(11) NOT NULL,
  `Folder_id` int(11) NOT NULL,
  `Doc_id` int(11) NOT NULL,
  `document_URL` varchar(512) NOT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `file_size` int(11) DEFAULT NULL,
  `file_type` varchar(100) DEFAULT NULL,
  `Is_accepted` enum('y','n','pending') NOT NULL DEFAULT 'pending',
  `Comments` text DEFAULT NULL,
  `Commented_by` int(11) DEFAULT NULL,
  `Status` varchar(255) DEFAULT NULL,
  `submitted_by` int(11) NOT NULL,
  `submitted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `reviewed_at` datetime DEFAULT NULL,
  `Is_active` enum('y','n') NOT NULL DEFAULT 'y'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `folder_doc_mapping`
--

CREATE TABLE `folder_doc_mapping` (
  `Folder_doc_mapping_id` int(11) NOT NULL,
  `Clg_id` int(11) NOT NULL,
  `Folder_id` int(11) NOT NULL,
  `Doc_id` int(11) NOT NULL,
  `Submit_before` datetime DEFAULT NULL,
  `Created_by` int(11) DEFAULT NULL,
  `Created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `Is_active` enum('y','n') NOT NULL DEFAULT 'y',
  `Is_mandate` enum('y','n') NOT NULL DEFAULT 'n'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `folder_mapping_to_students`
--

CREATE TABLE `folder_mapping_to_students` (
  `Folder_mapping_id` int(11) NOT NULL,
  `Clg_id` int(11) NOT NULL,
  `Class_id` int(11) NOT NULL,
  `Student_id` int(11) NOT NULL,
  `Folder_id` int(11) NOT NULL,
  `mapped_by` int(11) NOT NULL,
  `mapped_at` datetime NOT NULL DEFAULT current_timestamp(),
  `Is_active` enum('y','n') NOT NULL,
  `Is_mandate` enum('y','n') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `religion`
--

CREATE TABLE `religion` (
  `Religion_id` int(11) NOT NULL,
  `Clg_id` int(11) NOT NULL,
  `Religion_name` varchar(255) NOT NULL,
  `Created_by` int(11) NOT NULL,
  `Created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `Is_active` enum('y','n') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `religion`
--

INSERT INTO `religion` (`Religion_id`, `Clg_id`, `Religion_name`, `Created_by`, `Created_at`, `Is_active`) VALUES
(1, 1, 'HINDU', 1, '2025-05-28 19:45:50', 'y'),
(2, 1, 'SIKH', 1, '2025-05-28 19:46:05', 'y'),
(3, 1, 'MUSLIM', 1, '2025-05-28 19:46:23', 'y'),
(4, 1, 'CHRISTIAN', 1, '2025-05-28 19:46:36', 'y'),
(5, 1, 'BUDH', 1, '2025-05-28 19:46:48', 'y');

-- --------------------------------------------------------

--
-- Table structure for table `students`
--

CREATE TABLE `students` (
  `Student_id` int(11) NOT NULL,
  `Clg_id` int(11) NOT NULL,
  `Class_id` int(11) NOT NULL,
  `Religion_id` int(11) NOT NULL,
  `Caste_id` int(11) NOT NULL,
  `Student_address` text NOT NULL,
  `Father_name` varchar(255) NOT NULL,
  `Created_by` int(11) NOT NULL,
  `Created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `Is_active` enum('y','n') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `Uid` int(11) NOT NULL,
  `Name` varchar(255) NOT NULL,
  `Email` varchar(255) NOT NULL,
  `Phone` varchar(20) NOT NULL,
  `Pwd` varchar(255) NOT NULL,
  `Created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `Is_active` enum('y','n') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`Uid`, `Name`, `Email`, `Phone`, `Pwd`, `Created_at`, `Is_active`) VALUES
(1, 'Admin', 'gurpreetkumar.tx@gmail.com', '8146298641', '$2y$10$tqLszE.gsr88755rgT0yVuWBcj93noYrSreTx80Lh1dqpInsIbO6q', '2025-05-27 23:30:00', 'y');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admin`
--
ALTER TABLE `admin`
  ADD PRIMARY KEY (`admin_id`),
  ADD KEY `Clg_id` (`Clg_id`),
  ADD KEY `Uid` (`Uid`),
  ADD KEY `Created_by` (`Created_by`);

--
-- Indexes for table `caste`
--
ALTER TABLE `caste`
  ADD PRIMARY KEY (`Caste_id`),
  ADD KEY `Created_by` (`Created_by`),
  ADD KEY `fk_caste_clg` (`Clg_id`);

--
-- Indexes for table `classes`
--
ALTER TABLE `classes`
  ADD PRIMARY KEY (`Class_id`),
  ADD KEY `Clg_id` (`Clg_id`),
  ADD KEY `Created_by` (`Created_by`);

--
-- Indexes for table `clgs`
--
ALTER TABLE `clgs`
  ADD PRIMARY KEY (`Clg_id`),
  ADD KEY `Created_by` (`Created_by`);

--
-- Indexes for table `doc`
--
ALTER TABLE `doc`
  ADD PRIMARY KEY (`Doc_id`),
  ADD KEY `Clg_id` (`Clg_id`),
  ADD KEY `Created_by` (`Created_by`);

--
-- Indexes for table `folder`
--
ALTER TABLE `folder`
  ADD PRIMARY KEY (`Folder_id`),
  ADD KEY `Clg_id` (`Clg_id`),
  ADD KEY `Created_by` (`Created_by`);

--
-- Indexes for table `folder_docs_submitted_students`
--
ALTER TABLE `folder_docs_submitted_students`
  ADD PRIMARY KEY (`folder_doc_submission_id`),
  ADD UNIQUE KEY `uq_student_folder_doc` (`Student_id`,`Folder_id`,`Doc_id`,`Clg_id`),
  ADD KEY `Clg_id` (`Clg_id`),
  ADD KEY `Class_id` (`Class_id`),
  ADD KEY `Folder_id` (`Folder_id`),
  ADD KEY `Doc_id` (`Doc_id`),
  ADD KEY `Commented_by` (`Commented_by`),
  ADD KEY `submitted_by` (`submitted_by`);

--
-- Indexes for table `folder_doc_mapping`
--
ALTER TABLE `folder_doc_mapping`
  ADD PRIMARY KEY (`Folder_doc_mapping_id`),
  ADD UNIQUE KEY `uq_folder_doc_clg` (`Folder_id`,`Doc_id`,`Clg_id`),
  ADD KEY `Clg_id` (`Clg_id`),
  ADD KEY `Doc_id` (`Doc_id`),
  ADD KEY `Created_by` (`Created_by`);

--
-- Indexes for table `folder_mapping_to_students`
--
ALTER TABLE `folder_mapping_to_students`
  ADD PRIMARY KEY (`Folder_mapping_id`),
  ADD UNIQUE KEY `UQ_folder_student_mapping` (`Clg_id`,`Folder_id`,`Student_id`),
  ADD KEY `Class_id` (`Class_id`),
  ADD KEY `Student_id` (`Student_id`),
  ADD KEY `Folder_id` (`Folder_id`),
  ADD KEY `mapped_by` (`mapped_by`);

--
-- Indexes for table `religion`
--
ALTER TABLE `religion`
  ADD PRIMARY KEY (`Religion_id`),
  ADD KEY `Created_by` (`Created_by`),
  ADD KEY `fk_religion_clg` (`Clg_id`);

--
-- Indexes for table `students`
--
ALTER TABLE `students`
  ADD PRIMARY KEY (`Student_id`),
  ADD KEY `Clg_id` (`Clg_id`),
  ADD KEY `Class_id` (`Class_id`),
  ADD KEY `Religion_id` (`Religion_id`),
  ADD KEY `Caste_id` (`Caste_id`),
  ADD KEY `Created_by` (`Created_by`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`Uid`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admin`
--
ALTER TABLE `admin`
  MODIFY `admin_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `caste`
--
ALTER TABLE `caste`
  MODIFY `Caste_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `classes`
--
ALTER TABLE `classes`
  MODIFY `Class_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `clgs`
--
ALTER TABLE `clgs`
  MODIFY `Clg_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `doc`
--
ALTER TABLE `doc`
  MODIFY `Doc_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `folder`
--
ALTER TABLE `folder`
  MODIFY `Folder_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `folder_docs_submitted_students`
--
ALTER TABLE `folder_docs_submitted_students`
  MODIFY `folder_doc_submission_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `folder_doc_mapping`
--
ALTER TABLE `folder_doc_mapping`
  MODIFY `Folder_doc_mapping_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `folder_mapping_to_students`
--
ALTER TABLE `folder_mapping_to_students`
  MODIFY `Folder_mapping_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `religion`
--
ALTER TABLE `religion`
  MODIFY `Religion_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `Uid` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `admin`
--
ALTER TABLE `admin`
  ADD CONSTRAINT `admin_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `users` (`Uid`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `admin_ibfk_2` FOREIGN KEY (`Clg_id`) REFERENCES `clgs` (`Clg_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `admin_ibfk_3` FOREIGN KEY (`Uid`) REFERENCES `users` (`Uid`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `admin_ibfk_4` FOREIGN KEY (`Created_by`) REFERENCES `users` (`Uid`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `caste`
--
ALTER TABLE `caste`
  ADD CONSTRAINT `caste_ibfk_1` FOREIGN KEY (`Created_by`) REFERENCES `users` (`Uid`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_caste_clg` FOREIGN KEY (`Clg_id`) REFERENCES `clgs` (`Clg_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `classes`
--
ALTER TABLE `classes`
  ADD CONSTRAINT `classes_ibfk_1` FOREIGN KEY (`Clg_id`) REFERENCES `clgs` (`Clg_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `classes_ibfk_2` FOREIGN KEY (`Created_by`) REFERENCES `users` (`Uid`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `clgs`
--
ALTER TABLE `clgs`
  ADD CONSTRAINT `clgs_ibfk_1` FOREIGN KEY (`Created_by`) REFERENCES `users` (`Uid`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `doc`
--
ALTER TABLE `doc`
  ADD CONSTRAINT `doc_ibfk_1` FOREIGN KEY (`Clg_id`) REFERENCES `clgs` (`Clg_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `doc_ibfk_2` FOREIGN KEY (`Created_by`) REFERENCES `users` (`Uid`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `folder`
--
ALTER TABLE `folder`
  ADD CONSTRAINT `folder_ibfk_1` FOREIGN KEY (`Clg_id`) REFERENCES `clgs` (`Clg_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `folder_ibfk_2` FOREIGN KEY (`Created_by`) REFERENCES `users` (`Uid`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `folder_docs_submitted_students`
--
ALTER TABLE `folder_docs_submitted_students`
  ADD CONSTRAINT `folder_docs_submitted_students_ibfk_1` FOREIGN KEY (`Clg_id`) REFERENCES `clgs` (`Clg_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `folder_docs_submitted_students_ibfk_2` FOREIGN KEY (`Class_id`) REFERENCES `classes` (`Class_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `folder_docs_submitted_students_ibfk_3` FOREIGN KEY (`Student_id`) REFERENCES `users` (`Uid`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `folder_docs_submitted_students_ibfk_4` FOREIGN KEY (`Folder_id`) REFERENCES `folder` (`Folder_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `folder_docs_submitted_students_ibfk_5` FOREIGN KEY (`Doc_id`) REFERENCES `doc` (`Doc_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `folder_docs_submitted_students_ibfk_6` FOREIGN KEY (`Commented_by`) REFERENCES `users` (`Uid`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `folder_docs_submitted_students_ibfk_7` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`Uid`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `folder_doc_mapping`
--
ALTER TABLE `folder_doc_mapping`
  ADD CONSTRAINT `folder_doc_mapping_ibfk_1` FOREIGN KEY (`Clg_id`) REFERENCES `clgs` (`Clg_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `folder_doc_mapping_ibfk_2` FOREIGN KEY (`Folder_id`) REFERENCES `folder` (`Folder_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `folder_doc_mapping_ibfk_3` FOREIGN KEY (`Doc_id`) REFERENCES `doc` (`Doc_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `folder_doc_mapping_ibfk_4` FOREIGN KEY (`Created_by`) REFERENCES `users` (`Uid`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `folder_mapping_to_students`
--
ALTER TABLE `folder_mapping_to_students`
  ADD CONSTRAINT `folder_mapping_to_students_ibfk_1` FOREIGN KEY (`Clg_id`) REFERENCES `clgs` (`Clg_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `folder_mapping_to_students_ibfk_2` FOREIGN KEY (`Class_id`) REFERENCES `classes` (`Class_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `folder_mapping_to_students_ibfk_3` FOREIGN KEY (`Student_id`) REFERENCES `students` (`Student_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `folder_mapping_to_students_ibfk_4` FOREIGN KEY (`Folder_id`) REFERENCES `folder` (`Folder_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `folder_mapping_to_students_ibfk_5` FOREIGN KEY (`mapped_by`) REFERENCES `users` (`Uid`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `religion`
--
ALTER TABLE `religion`
  ADD CONSTRAINT `fk_religion_clg` FOREIGN KEY (`Clg_id`) REFERENCES `clgs` (`Clg_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `religion_ibfk_1` FOREIGN KEY (`Created_by`) REFERENCES `users` (`Uid`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `students`
--
ALTER TABLE `students`
  ADD CONSTRAINT `students_ibfk_1` FOREIGN KEY (`Student_id`) REFERENCES `users` (`Uid`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `students_ibfk_2` FOREIGN KEY (`Clg_id`) REFERENCES `clgs` (`Clg_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `students_ibfk_3` FOREIGN KEY (`Class_id`) REFERENCES `classes` (`Class_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `students_ibfk_4` FOREIGN KEY (`Religion_id`) REFERENCES `religion` (`Religion_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `students_ibfk_5` FOREIGN KEY (`Caste_id`) REFERENCES `caste` (`Caste_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `students_ibfk_6` FOREIGN KEY (`Created_by`) REFERENCES `users` (`Uid`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
