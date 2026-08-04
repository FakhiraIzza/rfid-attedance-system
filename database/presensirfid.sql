-- Struktur dari tabel `attendance`
CREATE TABLE `attendance` (
  `id_absensi` bigint(20) NOT NULL,
  `id_siswa` int(11) NOT NULL,
  `scan_at` datetime NOT NULL,
  `tanggal` date NOT NULL,
  `type` enum('MASUK','PULANG') NOT NULL,
  `status` enum('Hadir Tepat Waktu','Hadir Terlambat','Pulang','Izin','Sakit','Alfa') NOT NULL,
  `catatan` varchar(255) DEFAULT NULL,
  `keterangan` text DEFAULT NULL,
  `source` enum('device','manual') DEFAULT 'device',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Struktur dari tabel `logs`
CREATE TABLE `logs` (
  `id_log` int(11) NOT NULL,
  `waktu` datetime DEFAULT current_timestamp(),
  `status_sinkron` enum('Offline','Online','Tersinkron') DEFAULT 'Offline',
  `pesan_log` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Struktur dari tabel `parents`
CREATE TABLE `parents` (
  `id_orangtua` int(11) NOT NULL,
  `nama_orangtua` varchar(100) NOT NULL,
  `no_hp` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `alamat` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

---Struktur dari tabel `rfid_cards`
CREATE TABLE `rfid_cards` (
  `id_kartu` int(11) NOT NULL,
  `rfid_uid` varchar(32) NOT NULL,
  `status_kartu` enum('Aktif','Tidak Aktif') DEFAULT 'Aktif',
  `assigned_student_id` int(11) DEFAULT NULL,
  `tanggal_daftar` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Struktur dari tabel `settings`
CREATE TABLE `settings` (
  `key` varchar(64) NOT NULL,
  `value` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Struktur dari tabel `message_templates`
CREATE TABLE `message_templates` (
  `id_template` int(11) NOT NULL,
  `title` varchar(120) NOT NULL,
  `message_body` text NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `created_by_user_id` int(11) DEFAULT NULL,
  `updated_by_user_id` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Struktur dari tabel `users`
CREATE TABLE `users` (
  `id_user` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `reset_token_hash` varchar(64) DEFAULT NULL,
  `reset_token_expires` datetime DEFAULT NULL,
  `role` enum('admin','guru','orangtua') NOT NULL,
  `nama_lengkap` varchar(100) DEFAULT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `teacher_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Struktur dari tabel `sync_logs`
CREATE TABLE `sync_logs` (
  `id_log` bigint(20) NOT NULL,
  `device_id` varchar(64) DEFAULT NULL,
  `waktu` datetime NOT NULL,
  `status_sinkron` enum('Offline','Online','Tersinkron','Gagal') DEFAULT 'Online',
  `pesan_log` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Struktur dari tabel `wa_log`
CREATE TABLE `wa_log` (
  `id` int(10) UNSIGNED NOT NULL,
  `uid` varchar(50) NOT NULL,
  `wa_time_ms` double NOT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- Struktur dari tabel `attendance_evidence`
CREATE TABLE `attendance_evidence` (
  `id_evidence` bigint(20) NOT NULL,
  `attendance_id` bigint(20) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `original_filename` varchar(255) DEFAULT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `file_size` int(11) DEFAULT NULL,
  `captured_at` datetime DEFAULT NULL,
  `evidence_note` varchar(255) DEFAULT NULL,
  `uploaded_by_user_id` int(11) DEFAULT NULL,
  `uploader_role` varchar(20) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

