<?php

/**
 * Plugin Name: Simple WP DB Dumper
 * Description: Plugin sederhana untuk mendownload seluruh tabel database WordPress menjadi file .sql.
 * Version: 1.2
 * Author: Admin
 */

// Mencegah akses langsung
if (!defined('ABSPATH')) {
    exit;
}

// ==========================================
// 1. DEBUGGING SAAT AKTIVASI
// ==========================================
register_activation_hook(__FILE__, 'wp_db_dumper_debug_activation');
function wp_db_dumper_debug_activation()
{
    error_log("==================================================");
    error_log("[WP DB Dumper] Proses aktivasi plugin dimulai...");

    if (version_compare(PHP_VERSION, '5.6.0', '<')) {
        error_log("[WP DB Dumper] ERROR: Versi PHP terlalu lama (" . PHP_VERSION . ").");
        die("Plugin membutuhkan PHP versi 5.6 ke atas.");
    }

    error_log("[WP DB Dumper] Aktivasi berhasil tanpa fatal error.");
    error_log("==================================================");
}


// ==========================================
// 2. MEMBUAT MENU ADMIN
// ==========================================
add_action('admin_menu', 'wp_db_dumper_menu');
function wp_db_dumper_menu()
{
    add_management_page(
        'Database Dumper',
        'DB Dumper',
        'manage_options',
        'wp-db-dumper',
        'wp_db_dumper_page'
    );
}


// ==========================================
// 3. TAMPILAN HALAMAN PLUGIN (Dengan Info PHP)
// ==========================================
function wp_db_dumper_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }
?>
    <div class="wrap">
        <h1>WordPress Database Dumper</h1>
        <p>Klik tombol di bawah ini untuk mendownload seluruh isi database Anda dalam format <strong>.sql</strong>.</p>

        <div style="background: #fff; padding: 10px 15px; border-left: 4px solid #2271b1; margin-bottom: 20px; display: inline-block;">
            <strong>Informasi Server:</strong><br>
            Versi PHP yang digunakan: <code><?php echo PHP_VERSION; ?></code>
        </div>

        <form method="post" action="">
            <?php wp_nonce_field('wp_db_dump_action', 'wp_db_dump_nonce'); ?>
            <input type="hidden" name="wp_db_dump_trigger" value="1">
            <?php submit_button('Download Database', 'primary'); ?>
        </form>
    </div>
<?php
}


// ==========================================
// 4. PROSES EXPORT DATABASE
// ==========================================
add_action('admin_init', 'wp_db_dumper_process');
function wp_db_dumper_process()
{
    // Cek apakah tombol ditekan
    $is_triggered = isset($_POST['wp_db_dump_trigger']) ? $_POST['wp_db_dump_trigger'] : '';

    if ($is_triggered === '1') {

        // Cek keamanan dipisah menjadi beberapa baris agar tidak ada error copy-paste simbol
        $is_nonce_set = isset($_POST['wp_db_dump_nonce']);
        if (!$is_nonce_set) {
            wp_die('Akses ditolak: Nonce tidak ditemukan.');
        }

        $is_nonce_valid = wp_verify_nonce($_POST['wp_db_dump_nonce'], 'wp_db_dump_action');
        if (!$is_nonce_valid) {
            wp_die('Akses ditolak: Nonce tidak valid.');
        }

        if (!current_user_can('manage_options')) {
            wp_die('Akses ditolak: Anda tidak memiliki izin.');
        }

        global $wpdb;
        $tables = $wpdb->get_col("SHOW TABLES");
        if (empty($tables)) {
            wp_die('Tidak ada tabel yang ditemukan.');
        }

        $server_name = sanitize_title(get_bloginfo('name'));
        $filename = 'db-dump-' . $server_name . '-' . date('Y-m-d_H-i-s') . '.sql';

        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');

        if (ob_get_level()) {
            ob_clean();
        }
        flush();

        echo "-- WordPress Database Dump\n";
        echo "-- Waktu Ekspor: " . date('Y-m-d H:i:s') . "\n";
        echo "-- URL: " . get_bloginfo('url') . "\n";
        echo "-- Versi PHP: " . PHP_VERSION . "\n\n";
        echo "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
        echo "SET time_zone = \"+00:00\";\n\n";

        foreach ($tables as $table) {
            echo "-- Struktur dari tabel `$table`\n";
            echo "DROP TABLE IF EXISTS `$table`;\n";

            $create_table = $wpdb->get_row("SHOW CREATE TABLE `$table`", ARRAY_N);
            echo $create_table[1] . ";\n\n";

            echo "-- Data dari tabel `$table`\n";
            $rows = $wpdb->get_results("SELECT * FROM `$table`", ARRAY_A);

            if ($rows) {
                foreach ($rows as $row) {
                    $values = array_map(function ($value) use ($wpdb) {
                        if (is_null($value)) return 'NULL';
                        return "'" . esc_sql($value) . "'";
                    }, $row);
                    echo "INSERT INTO `$table` VALUES(" . implode(", ", $values) . ");\n";
                }
            }
            echo "\n\n";
            flush(); // Mengirim data ke browser perlahan agar tidak membebani memori
        }
        exit;
    }
}
