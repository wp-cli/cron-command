<?php

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

$wpcli_cron_autoload = dirname( __FILE__ ) . '/vendor/autoload.php';
if ( file_exists( $wpcli_cron_autoload ) ) {
	require_once $wpcli_cron_autoload;
}

WP_CLI::add_command( 'cron', 'Cron_Command' );
WP_CLI::add_command( 'cron event', 'Cron_Event_Command' );
WP_CLI::add_command( 'cron schedule', 'Cron_Schedule_Command' );
