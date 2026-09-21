-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 16, 2026 at 02:55 PM
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
-- Database: `live_teaching_system`
--

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `session_date` date NOT NULL,
  `status` enum('present','absent','late') NOT NULL DEFAULT 'present',
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `attendance`
--

INSERT INTO `attendance` (`id`, `student_id`, `class_id`, `session_date`, `status`, `notes`) VALUES
(1, 2, 1, '2026-06-12', 'present', NULL),
(2, 3, 4, '2026-09-07', 'present', NULL),
(3, 3, 1, '2026-09-14', 'present', 'Good');

-- --------------------------------------------------------

--
-- Table structure for table `course_lessons`
--

CREATE TABLE `course_lessons` (
  `id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `video_url` varchar(500) DEFAULT NULL,
  `video_type` enum('youtube','vimeo','upload','embed') DEFAULT 'upload',
  `transcript` longtext DEFAULT NULL,
  `transcript_text` longtext DEFAULT NULL,
  `duration` varchar(50) DEFAULT NULL,
  `order_position` int(11) NOT NULL DEFAULT 0,
  `is_free_preview` tinyint(1) NOT NULL DEFAULT 0,
  `is_locked` tinyint(1) NOT NULL DEFAULT 0,
  `primary_document_id` int(11) DEFAULT NULL,
  `status` enum('draft','published') NOT NULL DEFAULT 'draft',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `course_lessons`
--

INSERT INTO `course_lessons` (`id`, `class_id`, `teacher_id`, `title`, `description`, `video_url`, `video_type`, `transcript`, `transcript_text`, `duration`, `order_position`, `is_free_preview`, `is_locked`, `primary_document_id`, `status`, `created_at`, `updated_at`) VALUES
(1, 3, 2, 'Zulu', '', 'uploads/videos/lesson_6a2c1ee7f2c52_1781276391.mp4', 'upload', NULL, NULL, '', 1, 0, 0, NULL, 'draft', '2026-06-12 14:59:51', '2026-06-12 14:59:51'),
(2, 1, 2, 'Testing', '', 'uploads/videos/lesson_6aaa694fe8ea9_1789552975.mp4', 'upload', NULL, NULL, '', 1, 0, 0, NULL, 'published', '2026-06-12 15:18:08', '2026-09-16 10:02:55'),
(6, 1, 2, 'Testing 2', '', 'uploads/videos/lesson_6aaa6db2d6026_1789554098.mp4', 'upload', NULL, NULL, '', 2, 0, 0, NULL, 'published', '2026-09-16 10:04:47', '2026-09-16 12:31:24'),
(7, 4, 2, 'IsiZulu lesson 1', '', 'uploads/videos/lesson_6aaa710dee032_1789554957.mp4', 'upload', NULL, NULL, '', 1, 0, 1, NULL, 'published', '2026-09-16 10:35:57', '2026-09-16 11:25:19');

-- --------------------------------------------------------

--
-- Table structure for table `documents`
--

CREATE TABLE `documents` (
  `id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `lesson_id` int(11) DEFAULT NULL,
  `teacher_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `category` enum('past_paper','memo','worksheet','notes','slides','textbook','other') NOT NULL DEFAULT 'other',
  `original_name` varchar(255) NOT NULL,
  `storage_path` varchar(500) NOT NULL,
  `mime_type` varchar(150) NOT NULL,
  `size_bytes` bigint(20) UNSIGNED NOT NULL,
  `download_count` int(11) NOT NULL DEFAULT 0,
  `is_published` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `documents`
--

INSERT INTO `documents` (`id`, `class_id`, `lesson_id`, `teacher_id`, `title`, `description`, `category`, `original_name`, `storage_path`, `mime_type`, `size_bytes`, `download_count`, `is_published`, `created_at`, `updated_at`) VALUES
(2, 1, 6, 2, 'Maths P2', '', 'past_paper', 'marty_the_robot.pdf', 'uploads/documents/1/f53627fb2587a5b7e11eb63d.pdf', 'application/pdf', 12000161, 0, 1, '2026-09-16 12:31:52', '2026-09-16 12:31:52');

-- --------------------------------------------------------

--
-- Table structure for table `enrollments`
--

CREATE TABLE `enrollments` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `payment_status` enum('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
  `payment_method` varchar(100) DEFAULT NULL,
  `payment_reference` varchar(255) DEFAULT NULL,
  `amount_paid` decimal(10,2) DEFAULT 0.00,
  `paid_at` datetime DEFAULT NULL,
  `attendance` int(11) NOT NULL DEFAULT 0,
  `certificate_issued` tinyint(1) NOT NULL DEFAULT 0,
  `enrolled_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `enrollments`
--

INSERT INTO `enrollments` (`id`, `student_id`, `class_id`, `payment_status`, `payment_method`, `payment_reference`, `amount_paid`, `paid_at`, `attendance`, `certificate_issued`, `enrolled_at`) VALUES
(1, 2, 1, 'refunded', NULL, NULL, 0.00, NULL, 1, 0, '2026-06-12 11:31:08'),
(2, 2, 2, 'paid', 'eft', 'PAY-6A2C18FC7200C', 399.00, NULL, 0, 0, '2026-06-12 14:22:02'),
(3, 2, 3, 'paid', 'card', 'PAY-6A2C1910BC4A9', 599.00, NULL, 0, 0, '2026-06-12 14:34:52'),
(4, 3, 1, 'paid', 'card', 'PAY-6A9E82D694A7E', 499.00, NULL, 1, 0, '2026-09-07 09:24:35'),
(5, 3, 4, 'paid', 'eft', 'PAY-6AA8F03036F64', 200.00, NULL, 1, 1, '2026-09-07 11:04:40'),
(6, 3, 2, 'pending', NULL, NULL, 0.00, NULL, 0, 0, '2026-09-14 12:17:02');

-- --------------------------------------------------------

--
-- Table structure for table `lesson_progress`
--

CREATE TABLE `lesson_progress` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `lesson_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `status` enum('not_started','in_progress','completed') NOT NULL DEFAULT 'not_started',
  `watched_duration` int(11) NOT NULL DEFAULT 0,
  `last_position` int(11) NOT NULL DEFAULT 0,
  `completed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `lesson_progress`
--

INSERT INTO `lesson_progress` (`id`, `student_id`, `lesson_id`, `class_id`, `status`, `watched_duration`, `last_position`, `completed_at`, `created_at`, `updated_at`) VALUES
(1, 3, 2, 1, 'completed', 0, 0, '2026-09-15 09:45:19', '2026-09-15 07:45:19', '2026-09-15 07:45:19'),
(2, 3, 7, 4, 'completed', 0, 0, '2026-09-16 12:45:34', '2026-09-16 10:45:34', '2026-09-16 10:45:34'),
(3, 3, 6, 1, 'completed', 202, 202, '2026-09-16 12:47:19', '2026-09-16 10:47:08', '2026-09-16 10:47:19');

-- --------------------------------------------------------

--
-- Table structure for table `lesson_quizzes`
--

CREATE TABLE `lesson_quizzes` (
  `id` int(11) NOT NULL,
  `lesson_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `question` text NOT NULL,
  `options` text NOT NULL,
  `correct_answer` varchar(255) NOT NULL,
  `points` int(11) NOT NULL DEFAULT 1,
  `order_position` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `live_classes`
--

CREATE TABLE `live_classes` (
  `id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `short_description` varchar(500) DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `duration` varchar(50) DEFAULT NULL,
  `level` enum('beginner','intermediate','advanced') NOT NULL DEFAULT 'beginner',
  `category` varchar(100) DEFAULT NULL,
  `start_date` datetime DEFAULT NULL,
  `end_date` datetime DEFAULT NULL,
  `schedule` text DEFAULT NULL,
  `meeting_link` varchar(500) DEFAULT NULL,
  `meeting_id` varchar(100) DEFAULT NULL,
  `meeting_password` varchar(100) DEFAULT NULL,
  `recording_url` varchar(500) DEFAULT NULL,
  `materials` text DEFAULT NULL,
  `max_students` int(11) DEFAULT 50,
  `current_students` int(11) NOT NULL DEFAULT 0,
  `status` enum('upcoming','ongoing','completed','cancelled') NOT NULL DEFAULT 'upcoming',
  `is_featured` tinyint(1) NOT NULL DEFAULT 0,
  `sequential_unlock` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `thumbnail` varchar(500) DEFAULT NULL,
  `preview_video` varchar(500) DEFAULT NULL,
  `stream_platform` enum('zoom','google_meet','custom','youtube_live') DEFAULT 'zoom',
  `stream_embed` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `live_classes`
--

INSERT INTO `live_classes` (`id`, `teacher_id`, `title`, `description`, `short_description`, `image`, `price`, `duration`, `level`, `category`, `start_date`, `end_date`, `schedule`, `meeting_link`, `meeting_id`, `meeting_password`, `recording_url`, `materials`, `max_students`, `current_students`, `status`, `is_featured`, `sequential_unlock`, `created_at`, `thumbnail`, `preview_video`, `stream_platform`, `stream_embed`) VALUES
(1, 2, 'Mathematics Grade 12 - Exam Prep', 'Comprehensive exam preparation for Grade 12 Mathematics learners. Calculus, Algebra, Geometry and more.', 'Ace your Matric exams with this intensive course', NULL, 499.00, '6 weeks', 'intermediate', 'Mathematics', '2026-06-19 11:25:49', NULL, NULL, 'https://meet.google.com/akq-ygwr-wgv', NULL, NULL, NULL, NULL, 50, 0, 'completed', 1, 0, '2026-06-12 09:25:49', NULL, NULL, 'google_meet', NULL),
(2, 2, 'English First Additional Language', 'Improve your English skills for academic and professional success.', 'Master English grammar, writing and comprehension', NULL, 399.00, '8 weeks', 'beginner', 'Languages', '2026-06-26 11:25:49', NULL, NULL, 'https://meet.jit.si/liveteach_class_2_b6d767d2', NULL, NULL, NULL, NULL, 50, 1, 'completed', 1, 0, '2026-06-12 09:25:49', NULL, NULL, '', NULL),
(4, 2, 'IsiZulu', 'Isizulu ulimi lwebele', '', NULL, 200.00, '2 weeks, 8 hours', 'beginner', 'IsiZulu', '2026-09-07 13:49:00', NULL, NULL, 'https://meet.jit.si/liveteach_class_4_a1d0c6e8', NULL, NULL, NULL, NULL, 4, 1, 'completed', 0, 0, '2026-09-07 09:49:31', NULL, NULL, '', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `live_streams`
--

CREATE TABLE `live_streams` (
  `id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `stream_key` varchar(255) NOT NULL,
  `stream_url` varchar(500) DEFAULT NULL,
  `embed_code` text DEFAULT NULL,
  `platform` varchar(50) DEFAULT 'jitsi',
  `status` enum('scheduled','live','ended','recorded') DEFAULT 'scheduled',
  `scheduled_time` datetime DEFAULT NULL,
  `actual_start_time` datetime DEFAULT NULL,
  `actual_end_time` datetime DEFAULT NULL,
  `viewer_count` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `live_streams`
--

INSERT INTO `live_streams` (`id`, `class_id`, `teacher_id`, `stream_key`, `stream_url`, `embed_code`, `platform`, `status`, `scheduled_time`, `actual_start_time`, `actual_end_time`, `viewer_count`, `created_at`) VALUES
(1, 3, 2, '', 'https://meet.jit.si/liveteach_class_3_6364d3f0f495b6ab9dcf8d3b5c6e0b01', 'https://meet.jit.si/liveteach_class_3_6364d3f0f495b6ab9dcf8d3b5c6e0b01', 'jitsi', 'live', '2026-06-12 13:50:54', '2026-06-12 13:50:54', NULL, 0, '2026-06-12 11:50:54'),
(2, 3, 2, '', 'https://meet.jit.si/liveteach_class_3_6364d3f0f495b6ab9dcf8d3b5c6e0b01', 'https://meet.jit.si/liveteach_class_3_6364d3f0f495b6ab9dcf8d3b5c6e0b01', 'jitsi', 'live', '2026-06-12 13:51:25', '2026-06-12 13:51:25', NULL, 0, '2026-06-12 11:51:25'),
(3, 3, 2, '', 'https://meet.jit.si/liveteach_class_3_6364d3f0f495b6ab9dcf8d3b5c6e0b01', 'https://meet.jit.si/liveteach_class_3_6364d3f0f495b6ab9dcf8d3b5c6e0b01', 'jitsi', 'live', '2026-06-12 13:51:56', '2026-06-12 13:51:56', NULL, 0, '2026-06-12 11:51:56'),
(4, 3, 2, '', 'https://meet.jit.si/liveteach_class_3_6364d3f0f495b6ab9dcf8d3b5c6e0b01', 'https://meet.jit.si/liveteach_class_3_6364d3f0f495b6ab9dcf8d3b5c6e0b01', 'jitsi', 'live', '2026-06-12 13:52:27', '2026-06-12 13:52:27', NULL, 0, '2026-06-12 11:52:27'),
(5, 3, 2, '', 'https://meet.jit.si/liveteach_class_3_6364d3f0f495b6ab9dcf8d3b5c6e0b01', 'https://meet.jit.si/liveteach_class_3_6364d3f0f495b6ab9dcf8d3b5c6e0b01', 'jitsi', 'live', '2026-06-12 13:52:58', '2026-06-12 13:52:58', NULL, 0, '2026-06-12 11:52:58'),
(6, 3, 2, '', 'https://meet.jit.si/liveteach_class_3_6364d3f0f495b6ab9dcf8d3b5c6e0b01', 'https://meet.jit.si/liveteach_class_3_6364d3f0f495b6ab9dcf8d3b5c6e0b01', 'jitsi', 'live', '2026-06-12 13:53:29', '2026-06-12 13:53:29', NULL, 0, '2026-06-12 11:53:29'),
(7, 3, 2, '', 'https://meet.jit.si/liveteach_class_3_6364d3f0f495b6ab9dcf8d3b5c6e0b01', 'https://meet.jit.si/liveteach_class_3_6364d3f0f495b6ab9dcf8d3b5c6e0b01', 'jitsi', 'live', '2026-06-12 13:54:00', '2026-06-12 13:54:00', NULL, 0, '2026-06-12 11:54:00'),
(8, 3, 2, '', 'https://meet.jit.si/liveteach_class_3_6364d3f0f495b6ab9dcf8d3b5c6e0b01', 'https://meet.jit.si/liveteach_class_3_6364d3f0f495b6ab9dcf8d3b5c6e0b01', 'jitsi', 'ended', '2026-06-12 13:54:31', '2026-06-12 13:54:31', '2026-06-12 13:55:34', 0, '2026-06-12 11:54:31'),
(9, 3, 2, '', 'https://meet.jit.si/liveteach_class_3_6364d3f0', 'https://meet.jit.si/liveteach_class_3_6364d3f0', 'jitsi', 'ended', '2026-06-12 14:47:49', '2026-06-12 14:47:49', '2026-06-12 14:50:27', 0, '2026-06-12 12:47:49'),
(10, 3, 2, '', 'https://meet.jit.si/liveteach_class_3_6364d3f0', 'https://meet.jit.si/liveteach_class_3_6364d3f0', 'jitsi', 'ended', '2026-06-12 14:51:17', '2026-06-12 14:51:17', '2026-06-12 14:59:45', 0, '2026-06-12 12:51:17'),
(11, 3, 2, '', 'https://meet.jit.si/liveteach_class_3_6364d3f0', 'https://meet.jit.si/liveteach_class_3_6364d3f0', 'jitsi', 'ended', '2026-06-12 15:14:19', '2026-06-12 15:14:19', '2026-06-12 15:23:44', 0, '2026-06-12 13:14:19'),
(12, 3, 2, '', 'https://meet.jit.si/liveteach_class_3_6364d3f0', 'https://meet.jit.si/liveteach_class_3_6364d3f0', 'jitsi', 'ended', '2026-06-12 15:23:53', '2026-06-12 15:23:53', '2026-06-12 15:24:38', 0, '2026-06-12 13:23:53'),
(13, 3, 2, '', 'https://meet.google.com/vog-gkmz-ffp', 'https://meet.google.com/vog-gkmz-ffp', 'google_meet', 'scheduled', '2026-06-12 15:28:00', NULL, NULL, 0, '2026-06-12 13:27:00'),
(14, 3, 2, '', 'https://meet.google.com/vog-gkmz-ffp', 'https://meet.google.com/vog-gkmz-ffp', 'zoom', 'live', '2026-06-12 15:31:31', '2026-06-12 15:31:31', NULL, 0, '2026-06-12 13:31:31'),
(15, 3, 2, '', 'https://meet.jit.si/liveteach_class_3_6364d3f0', 'https://meet.jit.si/liveteach_class_3_6364d3f0', 'jitsi', 'live', '2026-06-12 15:37:06', '2026-06-12 15:37:06', NULL, 0, '2026-06-12 13:37:06'),
(16, 3, 2, '', 'https://meet.jit.si/liveteach_class_3_6364d3f0f495b6ab9dcf8d3b5c6e0b01', 'https://meet.jit.si/liveteach_class_3_6364d3f0f495b6ab9dcf8d3b5c6e0b01', 'jitsi', 'live', '2026-06-12 15:39:24', '2026-06-12 15:39:24', NULL, 0, '2026-06-12 13:39:24'),
(17, 3, 2, '', 'https://meet.jit.si/liveteach_class_3_6364d3f0f495b6ab9dcf8d3b5c6e0b01', 'https://meet.jit.si/liveteach_class_3_6364d3f0f495b6ab9dcf8d3b5c6e0b01', 'jitsi', 'live', '2026-06-12 15:39:54', '2026-06-12 15:39:54', NULL, 0, '2026-06-12 13:39:54'),
(18, 3, 2, '', 'https://meet.jit.si/liveteach_class_3_6364d3f0f495b6ab9dcf8d3b5c6e0b01', 'https://meet.jit.si/liveteach_class_3_6364d3f0f495b6ab9dcf8d3b5c6e0b01', 'jitsi', 'live', '2026-06-12 15:40:25', '2026-06-12 15:40:25', NULL, 0, '2026-06-12 13:40:25'),
(19, 3, 2, '', 'https://meet.jit.si/liveteach_class_3_6364d3f0f495b6ab9dcf8d3b5c6e0b01', 'https://meet.jit.si/liveteach_class_3_6364d3f0f495b6ab9dcf8d3b5c6e0b01', 'jitsi', 'live', '2026-06-12 15:40:56', '2026-06-12 15:40:56', NULL, 0, '2026-06-12 13:40:56'),
(20, 3, 2, '', 'https://meet.jit.si/liveteach_class_3_6364d3f0f495b6ab9dcf8d3b5c6e0b01', 'https://meet.jit.si/liveteach_class_3_6364d3f0f495b6ab9dcf8d3b5c6e0b01', 'jitsi', 'live', '2026-06-12 15:41:27', '2026-06-12 15:41:27', NULL, 0, '2026-06-12 13:41:27'),
(21, 3, 2, '', 'https://meet.jit.si/liveteach_class_3_6364d3f0', 'https://meet.jit.si/liveteach_class_3_6364d3f0', 'jitsi', 'ended', '2026-06-12 15:48:25', '2026-06-12 15:48:25', '2026-06-12 15:49:15', 0, '2026-06-12 13:48:25'),
(22, 3, 2, '', 'https://meet.jit.si/liveteach_class_3_6364d3f0', 'https://meet.jit.si/liveteach_class_3_6364d3f0', 'jitsi', 'live', '2026-06-12 15:50:39', '2026-06-12 15:50:39', NULL, 0, '2026-06-12 13:50:39'),
(23, 3, 2, '', 'https://meet.jit.si/liveteach_class_3_6364d3f0', 'https://meet.jit.si/liveteach_class_3_6364d3f0', 'jitsi', 'ended', '2026-06-12 15:51:13', '2026-06-12 15:51:13', '2026-06-12 15:51:16', 0, '2026-06-12 13:51:13'),
(24, 1, 2, '', 'https://meet.jit.si/liveteach_class_1_c20ad4d7', 'https://meet.jit.si/liveteach_class_1_c20ad4d7', 'jitsi', 'live', '2026-06-12 15:51:45', '2026-06-12 15:51:45', NULL, 0, '2026-06-12 13:51:45'),
(25, 1, 2, '', 'https://meet.jit.si/liveteach_class_1_c20ad4d7', 'https://meet.jit.si/liveteach_class_1_c20ad4d7', 'jitsi', 'ended', '2026-06-12 15:52:18', '2026-06-12 15:52:18', '2026-06-12 15:52:45', 0, '2026-06-12 13:52:18'),
(26, 1, 2, '', 'https://meet.jit.si/liveteach_class_1_c20ad4d7', 'https://meet.jit.si/liveteach_class_1_c20ad4d7', 'jitsi', 'live', '2026-06-12 15:52:50', '2026-06-12 15:52:50', NULL, 0, '2026-06-12 13:52:50'),
(27, 1, 2, '', 'https://meet.jit.si/liveteach_class_1_c20ad4d7', 'https://meet.jit.si/liveteach_class_1_c20ad4d7', 'jitsi', 'live', '2026-06-12 17:00:35', '2026-06-12 17:00:35', NULL, 0, '2026-06-12 15:00:35'),
(28, 1, 2, '', 'https://meet.jit.si/liveteach_class_1_c20ad4d7', 'https://meet.jit.si/liveteach_class_1_c20ad4d7', 'jitsi', 'live', '2026-06-12 17:04:51', '2026-06-12 17:04:51', NULL, 0, '2026-06-12 15:04:51'),
(29, 1, 2, '', 'https://meet.jit.si/liveteach_class_1_c20ad4d7', 'https://meet.jit.si/liveteach_class_1_c20ad4d7', 'jitsi', 'live', '2026-06-12 17:05:46', '2026-06-12 17:05:46', NULL, 0, '2026-06-12 15:05:46'),
(30, 1, 2, '', 'https://meet.jit.si/liveteach_class_1_c20ad4d7', 'https://meet.jit.si/liveteach_class_1_c20ad4d7', 'jitsi', 'live', '2026-06-12 17:09:57', '2026-06-12 17:09:57', NULL, 0, '2026-06-12 15:09:57'),
(31, 1, 2, '', 'https://meet.jit.si/liveteach_class_1_c20ad4d7', 'https://meet.jit.si/liveteach_class_1_c20ad4d7', 'jitsi', 'ended', '2026-06-12 17:14:15', '2026-06-12 17:14:15', '2026-06-12 17:14:24', 0, '2026-06-12 15:14:15'),
(32, 2, 2, '', 'https://meet.jit.si/liveteach_class_2_b6d767d2', 'https://meet.jit.si/liveteach_class_2_b6d767d2', 'jitsi', 'ended', '2026-06-12 17:15:59', '2026-06-12 17:15:59', '2026-06-12 17:16:26', 0, '2026-06-12 15:15:59'),
(33, 1, 2, '', 'https://meet.jit.si/liveteach_class_1_c20ad4d7', 'https://meet.jit.si/liveteach_class_1_c20ad4d7', 'jitsi', 'live', '2026-06-13 15:13:13', '2026-06-13 15:13:13', NULL, 0, '2026-06-13 13:13:13'),
(34, 1, 2, '', 'https://meet.jit.si/liveteach_class_1_c20ad4d7', 'https://meet.jit.si/liveteach_class_1_c20ad4d7', 'jitsi', 'ended', '2026-06-13 15:13:43', '2026-06-13 15:13:43', '2026-06-13 15:13:55', 0, '2026-06-13 13:13:43'),
(35, 4, 2, '', 'https://meet.jit.si/liveteach_class_4_a1d0c6e8', 'https://meet.jit.si/liveteach_class_4_a1d0c6e8', 'jitsi', 'ended', '2026-09-07 13:04:11', '2026-09-07 13:04:11', '2026-09-07 13:06:37', 0, '2026-09-07 11:04:11'),
(36, 1, 2, '', 'https://meet.google.com/zoz-iwve-tzu', 'https://meet.google.com/zoz-iwve-tzu', 'google_meet', 'ended', '2026-09-14 13:13:10', '2026-09-14 13:13:10', '2026-09-14 13:15:06', 0, '2026-09-14 11:13:10'),
(37, 1, 2, '', 'https://meet.google.com/akq-ygwr-wgv', 'https://meet.google.com/akq-ygwr-wgv', 'google_meet', 'ended', '2026-09-14 14:20:03', '2026-09-14 14:20:03', '2026-09-14 14:23:05', 0, '2026-09-14 12:20:03');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `type` varchar(50) NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `link` varchar(500) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `type`, `title`, `message`, `link`, `is_read`, `created_at`) VALUES
(1, 2, 'live_stream', '🔴 Live Class Started!', 'The teacher has started the live session for \'Mathematics Grade 12 - Exam Prep\'. Join now!', '../classes/class.php?id=1#live-stream', 1, '2026-06-12 15:00:35'),
(2, 2, 'live_stream', '🔴 Live Class Started!', 'The teacher has started the live session for \'Mathematics Grade 12 - Exam Prep\'. Join now!', '../classes/class.php?id=1#live-stream', 1, '2026-06-12 15:04:51'),
(3, 2, 'live_stream', '🔴 Live Class Started!', 'The teacher has started the live session for \'Mathematics Grade 12 - Exam Prep\'. Join now!', '../classes/class.php?id=1#live-stream', 1, '2026-06-12 15:05:46'),
(4, 2, 'live_stream', '🔴 Live Class Started!', 'The teacher has started the live session for \'Mathematics Grade 12 - Exam Prep\'. Join now!', '../classes/class.php?id=1#live-stream', 1, '2026-06-12 15:09:57'),
(5, 3, 'enrollment', '✅ Enrollment Successful', 'You have successfully enrolled in \'Mathematics Grade 12 - Exam Prep\'.', '../dashboard/my-classes.php', 1, '2026-09-07 09:24:38'),
(6, 3, 'enrollment', '✅ Enrollment Successful', 'You have successfully enrolled in \'IsiZulu\'.', '../dashboard/my-classes.php', 1, '2026-09-07 11:04:43'),
(7, 3, 'enrollment', '✅ Enrollment Successful', 'You have successfully enrolled in \'IsiZulu\'.', '../dashboard/my-classes.php', 1, '2026-09-15 07:13:52');

-- --------------------------------------------------------

--
-- Table structure for table `notification_preferences`
--

CREATE TABLE `notification_preferences` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `enrollment_notifications` tinyint(1) NOT NULL DEFAULT 1,
  `live_stream_notifications` tinyint(1) NOT NULL DEFAULT 1,
  `certificate_notifications` tinyint(1) NOT NULL DEFAULT 1,
  `class_update_notifications` tinyint(1) NOT NULL DEFAULT 1,
  `email_notifications` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` varchar(100) DEFAULT NULL,
  `reference` varchar(255) DEFAULT NULL,
  `status` enum('pending','completed','failed','refunded') NOT NULL DEFAULT 'pending',
  `paid_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`id`, `student_id`, `class_id`, `amount`, `payment_method`, `reference`, `status`, `paid_at`, `created_at`) VALUES
(1, 2, 2, 399.00, 'eft', 'PAY-6A2C18FC7200C', 'completed', '2026-06-12 16:34:36', '2026-06-12 14:34:36'),
(2, 2, 3, 599.00, 'card', 'PAY-6A2C1910BC4A9', 'completed', '2026-06-12 16:34:56', '2026-06-12 14:34:56'),
(3, 3, 1, 499.00, 'card', 'PAY-6A9E82D694A7E', 'completed', '2026-09-07 11:24:38', '2026-09-07 09:24:38'),
(4, 3, 4, 200.00, 'card', 'PAY-6A9E9A4B2528E', 'completed', '2026-09-07 13:04:43', '2026-09-07 11:04:43'),
(5, 3, 4, 200.00, 'eft', 'PAY-6AA8F03036F64', 'completed', '2026-09-15 09:13:52', '2026-09-15 07:13:52');

-- --------------------------------------------------------

--
-- Table structure for table `reviews`
--

CREATE TABLE `reviews` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `rating` int(11) NOT NULL CHECK (`rating` >= 1 and `rating` <= 5),
  `comment` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `fullname` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `role` enum('admin','teacher','student') NOT NULL DEFAULT 'student',
  `profile_image` varchar(255) DEFAULT NULL,
  `bio` text DEFAULT NULL,
  `status` enum('active','inactive','suspended') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `fullname`, `email`, `password`, `phone`, `role`, `profile_image`, `bio`, `status`, `created_at`) VALUES
(1, 'System Admin', 'admin@teach.com', '$2y$10$CDoNefkSU1cp3naMH9upY.i8tUtuBQ4ErrdHvW7stK4.t47RlgTtO', NULL, 'admin', NULL, NULL, 'active', '2026-06-12 09:25:49'),
(2, 'Pro. Sonile Cebekhulu', 'teacher@teach.com', '$2y$10$CDoNefkSU1cp3naMH9upY.i8tUtuBQ4ErrdHvW7stK4.t47RlgTtO', NULL, 'teacher', NULL, 'Experienced educator with over 15 years of teaching experience. Specializes in Mathematics and Science.', 'active', '2026-06-12 09:25:49'),
(3, 'Student', 'student@gmail.com', '$2y$10$DXWQHBvuINNPOzGDjQOwqezL101ARDPdLvkRB1ZIiTfajcpWEftRy', '0799526467', 'student', NULL, NULL, 'active', '2026-09-07 09:10:05');

-- --------------------------------------------------------

--
-- Table structure for table `videos`
--

CREATE TABLE `videos` (
  `id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `lesson_id` int(11) DEFAULT NULL,
  `teacher_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `transcript` longtext DEFAULT NULL,
  `video_url` varchar(500) NOT NULL,
  `video_type` enum('youtube','vimeo','upload','recording') DEFAULT 'upload',
  `embed_code` text DEFAULT NULL,
  `duration` varchar(50) DEFAULT NULL,
  `thumbnail` varchar(500) DEFAULT NULL,
  `is_preview` tinyint(1) NOT NULL DEFAULT 0,
  `is_live_recording` tinyint(1) NOT NULL DEFAULT 0,
  `views` int(11) DEFAULT 0,
  `order_position` int(11) DEFAULT 0,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `videos`
--

INSERT INTO `videos` (`id`, `class_id`, `lesson_id`, `teacher_id`, `title`, `description`, `transcript`, `video_url`, `video_type`, `embed_code`, `duration`, `thumbnail`, `is_preview`, `is_live_recording`, `views`, `order_position`, `status`, `created_at`) VALUES
(2, 3, NULL, 2, 'maths', '', NULL, 'https://youtu.be/fk0wyv1HpFg?list=PLpjHwiufZtSJn5GlPnqb_KQuvCTbJ9T_6', 'youtube', NULL, NULL, '', 1, 0, 7, 0, 'active', '2026-06-12 10:44:46'),
(3, 3, NULL, 2, 'Solve For X', 'Will be finding the value of x and y', NULL, 'uploads/videos/video_6a2bf9718ee04_1781266801.mp4', 'upload', NULL, NULL, NULL, 0, 0, 0, 0, 'active', '2026-06-12 12:20:01'),
(4, 3, NULL, 2, 'Test', 'testing phase', NULL, 'https://youtu.be/fk0wyv1HpFg?list=PLpjHwiufZtSJn5GlPnqb_KQuvCTbJ9T_6', 'youtube', NULL, NULL, NULL, 0, 0, 0, 0, 'active', '2026-06-12 12:23:13');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_attendance_student` (`student_id`),
  ADD KEY `fk_attendance_class` (`class_id`);

--
-- Indexes for table `course_lessons`
--
ALTER TABLE `course_lessons`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_class_id` (`class_id`),
  ADD KEY `idx_teacher_id` (`teacher_id`),
  ADD KEY `idx_order` (`order_position`);

--
-- Indexes for table `documents`
--
ALTER TABLE `documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_class` (`class_id`),
  ADD KEY `idx_lesson` (`lesson_id`),
  ADD KEY `idx_teacher` (`teacher_id`),
  ADD KEY `idx_category` (`category`),
  ADD KEY `idx_published` (`is_published`);

--
-- Indexes for table `enrollments`
--
ALTER TABLE `enrollments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_enrollment` (`student_id`,`class_id`),
  ADD KEY `fk_enrollments_student` (`student_id`),
  ADD KEY `fk_enrollments_class` (`class_id`);

--
-- Indexes for table `lesson_progress`
--
ALTER TABLE `lesson_progress`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_progress` (`student_id`,`lesson_id`),
  ADD KEY `idx_student_id` (`student_id`),
  ADD KEY `idx_lesson_id` (`lesson_id`);

--
-- Indexes for table `lesson_quizzes`
--
ALTER TABLE `lesson_quizzes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_lesson_id` (`lesson_id`);

--
-- Indexes for table `live_classes`
--
ALTER TABLE `live_classes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_live_classes_teacher` (`teacher_id`);

--
-- Indexes for table `live_streams`
--
ALTER TABLE `live_streams`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_live_streams_class` (`class_id`),
  ADD KEY `fk_live_streams_teacher` (`teacher_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_is_read` (`is_read`);

--
-- Indexes for table `notification_preferences`
--
ALTER TABLE `notification_preferences`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_payments_student` (`student_id`),
  ADD KEY `fk_payments_class` (`class_id`);

--
-- Indexes for table `reviews`
--
ALTER TABLE `reviews`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_reviews_student` (`student_id`),
  ADD KEY `fk_reviews_class` (`class_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `videos`
--
ALTER TABLE `videos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_videos_class` (`class_id`),
  ADD KEY `fk_videos_teacher` (`teacher_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `course_lessons`
--
ALTER TABLE `course_lessons`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `documents`
--
ALTER TABLE `documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `enrollments`
--
ALTER TABLE `enrollments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `lesson_progress`
--
ALTER TABLE `lesson_progress`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `lesson_quizzes`
--
ALTER TABLE `lesson_quizzes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `live_classes`
--
ALTER TABLE `live_classes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `live_streams`
--
ALTER TABLE `live_streams`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=38;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `notification_preferences`
--
ALTER TABLE `notification_preferences`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `reviews`
--
ALTER TABLE `reviews`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `videos`
--
ALTER TABLE `videos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
