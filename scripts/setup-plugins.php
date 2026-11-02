<?php
/**
 * Cài và kích hoạt plugin bên thứ ba theo data/plugins.json, đúng phiên bản đã chốt.
 *
 * Mỗi plugin: { "slug", "version", "dev": true|false, "why" }.
 * Plugin "dev": chỉ cài khi môi trường là local (WP_ENVIRONMENT_TYPE), không lên server thật.
 *
 * Dùng WP_CLI::runcommand() để gọi lệnh "wp plugin ..." ngay trong tiến trình này:
 * một container cho mọi plugin, thay vì mỗi lệnh "wp" một container (~2–3 giây/lệnh).
 *
 * Chạy lặp lại được. Chạy: docker compose run --rm wpcli eval-file /scripts/setup-plugins.php
 */

$plugins = json_decode( file_get_contents( '/data/plugins.json' ), true );
if ( ! is_array( $plugins ) ) {
	WP_CLI::error( 'Không đọc được /data/plugins.json' );
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';
$is_local = 'local' === wp_get_environment_type();

foreach ( $plugins as $p ) {
	$slug    = $p['slug'];
	$version = $p['version'];

	if ( ! empty( $p['dev'] ) && ! $is_local ) {
		WP_CLI::log( sprintf( '    %-34s bỏ qua (chỉ dùng ở local)', $slug ) );
		continue;
	}

	// Phiên bản đang cài (nếu có): đọc header của file plugin chính.
	$installed = null;
	foreach ( get_plugins() as $file => $data ) {
		if ( dirname( $file ) === $slug ) {
			$installed = $data['Version'];
		}
	}

	if ( $installed !== $version ) {
		WP_CLI::runcommand(
			sprintf( 'plugin install %s --version=%s --force', escapeshellarg( $slug ), escapeshellarg( $version ) ),
			[ 'return' => true, 'exit_error' => true ]
		);
		WP_CLI::log( sprintf( '    %-34s %s → %s', $slug, $installed ?? 'chưa cài', $version ) );
	} else {
		WP_CLI::log( sprintf( '    %-34s %s (đã đúng phiên bản)', $slug, $version ) );
	}

	WP_CLI::runcommand( 'plugin activate ' . escapeshellarg( $slug ), [ 'return' => true, 'exit_error' => true ] );

	// Bản dịch tiếng Việt từ translate.wordpress.org (plugin nào chưa có bản dịch thì bỏ qua).
	if ( empty( $p['dev'] ) ) {
		WP_CLI::runcommand( 'language plugin install ' . escapeshellarg( $slug ) . ' vi', [ 'return' => true, 'exit_error' => false ] );
	}
}
