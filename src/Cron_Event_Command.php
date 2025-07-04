<?php

use WP_CLI\Utils;

/**
 * Schedules, runs, and deletes WP-Cron events.
 *
 * ## EXAMPLES
 *
 *     # Schedule a new cron event
 *     $ wp cron event schedule cron_test
 *     Success: Scheduled event with hook 'cron_test' for 2016-05-31 10:19:16 GMT.
 *
 *     # Run all cron events due right now
 *     $ wp cron event run --due-now
 *     Executed the cron event 'cron_test_1' in 0.01s.
 *     Executed the cron event 'cron_test_2' in 0.006s.
 *     Success: Executed a total of 2 cron events.
 *
 *     # Delete all scheduled cron events for the given hook
 *     $ wp cron event delete cron_test
 *     Success: Deleted a total of 2 cron events.
 *
 *     # List scheduled cron events in JSON
 *     $ wp cron event list --fields=hook,next_run --format=json
 *     [{"hook":"wp_version_check","next_run":"2016-05-31 10:15:13"},{"hook":"wp_update_plugins","next_run":"2016-05-31 10:15:13"},{"hook":"wp_update_themes","next_run":"2016-05-31 10:15:14"}]
 *
 * @package wp-cli
 */
class Cron_Event_Command extends WP_CLI_Command {
	/**
	 * @var string[]
	 */
	private $fields = array(
		'hook',
		'next_run_gmt',
		'next_run_relative',
		'recurrence',
	);

	/**
	 * @var string
	 */
	private static $time_format = 'Y-m-d H:i:s';

	/**
	 * Lists scheduled cron events.
	 *
	 * ## OPTIONS
	 *
	 * [--fields=<fields>]
	 * : Limit the output to specific object fields.
	 *
	 * [--<field>=<value>]
	 * : Filter by one or more fields.
	 *
	 * [--field=<field>]
	 * : Prints the value of a single field for each event.
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - ids
	 *   - json
	 *   - count
	 *   - yaml
	 * ---
	 *
	 * ## AVAILABLE FIELDS
	 *
	 * These fields will be displayed by default for each cron event:
	 * * hook
	 * * next_run_gmt
	 * * next_run_relative
	 * * recurrence
	 *
	 * These fields are optionally available:
	 * * time
	 * * sig
	 * * args
	 * * schedule
	 * * interval
	 * * next_run
	 *
	 * ## EXAMPLES
	 *
	 *     # List scheduled cron events
	 *     $ wp cron event list
	 *     +-------------------+---------------------+---------------------+------------+
	 *     | hook              | next_run_gmt        | next_run_relative   | recurrence |
	 *     +-------------------+---------------------+---------------------+------------+
	 *     | wp_version_check  | 2016-05-31 22:15:13 | 11 hours 57 minutes | 12 hours   |
	 *     | wp_update_plugins | 2016-05-31 22:15:13 | 11 hours 57 minutes | 12 hours   |
	 *     | wp_update_themes  | 2016-05-31 22:15:14 | 11 hours 57 minutes | 12 hours   |
	 *     +-------------------+---------------------+---------------------+------------+
	 *
	 *     # List scheduled cron events in JSON
	 *     $ wp cron event list --fields=hook,next_run --format=json
	 *     [{"hook":"wp_version_check","next_run":"2016-05-31 10:15:13"},{"hook":"wp_update_plugins","next_run":"2016-05-31 10:15:13"},{"hook":"wp_update_themes","next_run":"2016-05-31 10:15:14"}]
	 *
	 * @subcommand list
	 */
	public function list_( $args, $assoc_args ) {
		$formatter = $this->get_formatter( $assoc_args );

		$events = self::get_cron_events();

		if ( is_wp_error( $events ) ) {
			$events = array();
		}

		foreach ( $events as $key => $event ) {
			foreach ( $this->fields as $field ) {
				if ( ! empty( $assoc_args[ $field ] ) && $event->{$field} !== $assoc_args[ $field ] ) {
					unset( $events[ $key ] );
					break;
				}
			}
		}

		if ( 'ids' === $formatter->format ) {
			echo implode( ' ', wp_list_pluck( $events, 'hook' ) );
		} else {
			$formatter->display_items( $events );
		}
	}

	/**
	 * Schedules a new cron event.
	 *
	 * ## OPTIONS
	 *
	 * <hook>
	 * : The hook name.
	 *
	 * [<next-run>]
	 * : A Unix timestamp or an English textual datetime description compatible with `strtotime()`. Defaults to now.
	 *
	 * [<recurrence>]
	 * : How often the event should recur. See `wp cron schedule list` for available schedule names. Defaults to no recurrence.
	 *
	 * [--<field>=<value>]
	 * : Arguments to pass to the hook for the event. <field> should be a numeric key, not a string.
	 *
	 * ## EXAMPLES
	 *
	 *     # Schedule a new cron event
	 *     $ wp cron event schedule cron_test
	 *     Success: Scheduled event with hook 'cron_test' for 2016-05-31 10:19:16 GMT.
	 *
	 *     # Schedule new cron event with hourly recurrence
	 *     $ wp cron event schedule cron_test now hourly
	 *     Success: Scheduled event with hook 'cron_test' for 2016-05-31 10:20:32 GMT.
	 *
	 *     # Schedule new cron event and pass arguments
	 *     $ wp cron event schedule cron_test '+1 hour' --0=first-argument --1=second-argument
	 *     Success: Scheduled event with hook 'cron_test' for 2016-05-31 11:21:35 GMT.
	 *
	 * @param array{0: string, 1?: string, 2?: string} $args Positional arguments.
	 * @param array<int, string> $assoc_args Associative arguments.
	 */
	public function schedule( $args, $assoc_args ) {
		if ( count( $assoc_args ) && count( array_filter( array_keys( $assoc_args ), 'is_string' ) ) ) {
			WP_CLI::warning( 'Numeric keys should be used for the hook arguments.' );
		}

		$hook = $args[0];

		$next_run   = Utils\get_flag_value( $args, 1, 'now' );
		$recurrence = Utils\get_flag_value( $args, 2, false );

		if ( empty( $next_run ) ) {
			$timestamp = time();
		} elseif ( is_numeric( $next_run ) ) {
			$timestamp = absint( $next_run );
		} else {
			$timestamp = strtotime( $next_run );
		}

		if ( ! $timestamp ) {
			WP_CLI::error( sprintf( "'%s' is not a valid datetime.", $next_run ) );
		}

		if ( ! empty( $recurrence ) ) {

			$schedules = wp_get_schedules();

			if ( ! isset( $schedules[ $recurrence ] ) ) {
				WP_CLI::error( sprintf( "'%s' is not a valid schedule name for recurrence.", $recurrence ) );
			}

			$event = wp_schedule_event( $timestamp, $recurrence, $hook, $assoc_args );

		} else {

			$event = wp_schedule_single_event( $timestamp, $hook, $assoc_args );

		}

		if ( false !== $event ) {
			WP_CLI::success( sprintf( "Scheduled event with hook '%s' for %s GMT.", $hook, date( self::$time_format, $timestamp ) ) ); //phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
		} else {
			WP_CLI::error( 'Event not scheduled.' );
		}
	}

	/**
	 * Runs the next scheduled cron event for the given hook.
	 *
	 * ## OPTIONS
	 *
	 * [<hook>...]
	 * : One or more hooks to run.
	 *
	 * [--due-now]
	 * : Run all hooks due right now.
	 *
	 * [--exclude=<hooks>]
	 * : Comma-separated list of hooks to exclude.
	 *
	 * [--all]
	 * : Run all hooks.
	 *
	 * ## EXAMPLES
	 *
	 *     # Run all cron events due right now
	 *     $ wp cron event run --due-now
	 *     Executed the cron event 'cron_test_1' in 0.01s.
	 *     Executed the cron event 'cron_test_2' in 0.006s.
	 *     Success: Executed a total of 2 cron events.
	 */
	public function run( $args, $assoc_args ) {

		$events = self::get_selected_cron_events( $args, $assoc_args );

		if ( is_wp_error( $events ) ) {
			WP_CLI::error( $events );
		}

		$executed = 0;
		foreach ( $events as $event ) {
			$start  = microtime( true );
			$result = self::run_event( $event );
			$total  = round( microtime( true ) - $start, 3 );
			++$executed;
			WP_CLI::log( sprintf( "Executed the cron event '%s' in %ss.", $event->hook, $total ) );
		}

		$message = ( 1 === $executed ) ? 'Executed a total of %d cron event.' : 'Executed a total of %d cron events.';
		WP_CLI::success( sprintf( $message, $executed ) );
	}

	/**
	 * Unschedules all cron events for a given hook.
	 *
	 * ## OPTIONS
	 *
	 * <hook>
	 * : Name of the hook for which all events should be unscheduled.
	 *
	 * ## EXAMPLES
	 *
	 *     # Unschedule a cron event on given hook.
	 *     $ wp cron event unschedule cron_test
	 *     Success: Unscheduled 2 events for hook 'cron_test'.
	 */
	public function unschedule( $args, $assoc_args ) {

		list( $hook ) = $args;

		$unscheduled = wp_unschedule_hook( $hook );

		if ( empty( $unscheduled ) ) {
			$message = 'Failed to unschedule events for hook \'%1\$s.';

			// If 0 event found on hook.
			if ( 0 === $unscheduled ) {
				$message = "No events found for hook '%1\$s'.";
			}

			WP_CLI::error( sprintf( $message, $hook ) );

		} else {
			WP_CLI::success(
				sprintf(
					'Unscheduled %1$d %2$s for hook \'%3$s\'.',
					$unscheduled,
					Utils\pluralize( 'event', $unscheduled ),
					$hook
				)
			);
		}
	}

	/**
	 * Deletes all scheduled cron events for the given hook.
	 *
	 * ## OPTIONS
	 *
	 * [<hook>...]
	 * : One or more hooks to delete.
	 *
	 * [--due-now]
	 * : Delete all hooks due right now.
	 *
	 * [--exclude=<hooks>]
	 * : Comma-separated list of hooks to exclude.
	 *
	 * [--all]
	 * : Delete all hooks.
	 *
	 * ## EXAMPLES
	 *
	 *     # Delete all scheduled cron events for the given hook
	 *     $ wp cron event delete cron_test
	 *     Success: Deleted a total of 2 cron events.
	 */
	public function delete( $args, $assoc_args ) {
		$events = self::get_selected_cron_events( $args, $assoc_args );

		if ( is_wp_error( $events ) ) {
			WP_CLI::error( $events );
		}

		$deleted = 0;
		foreach ( $events as $event ) {
			$result = self::delete_event( $event );
			if ( $result ) {
				++$deleted;
			} else {
				WP_CLI::warning( sprintf( "Failed to the delete the cron event '%s'.", $event->hook ) );
			}
		}

		$message = sprintf( 'Deleted a total of %d %s.', $deleted, Utils\pluralize( 'cron event', $deleted ) );
		WP_CLI::success( sprintf( $message, $deleted ) );
	}

	/**
	 * Executes an event immediately.
	 *
	 * @param stdClass $event The event
	 * @return bool Whether the event was successfully executed or not.
	 */
	protected static function run_event( stdClass $event ) {

		if ( ! defined( 'DOING_CRON' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Using native WordPress constant.
			define( 'DOING_CRON', true );
		}

		if ( false !== $event->schedule ) {
			wp_reschedule_event( $event->time, $event->schedule, $event->hook, $event->args );
		}

		wp_unschedule_event( $event->time, $event->hook, $event->args );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Can't prefix dynamic hooks here, calling registered hooks.
		do_action_ref_array( $event->hook, $event->args );

		return true;
	}

	/**
	 * Deletes a cron event.
	 *
	 * @param stdClass $event The event
	 * @return bool Whether the event was successfully deleted or not.
	 */
	protected static function delete_event( stdClass $event ) {
		$crons = _get_cron_array();

		if ( ! isset( $crons[ $event->time ][ $event->hook ][ $event->sig ] ) ) {
			return false;
		}

		wp_unschedule_event( $event->time, $event->hook, $event->args );
		return true;
	}

	/**
	 * Callback function to format a cron event.
	 *
	 * @param stdClass $event The event.
	 * @return stdClass The formatted event object.
	 */
	protected static function format_event( stdClass $event ) {

		$schedules                = wp_get_schedules();
		$event->recurrence        = ( isset( $schedules[ $event->schedule ] ) ) ? self::interval( $event->interval ) : 'Non-repeating';
		$event->next_run          = get_date_from_gmt( date( 'Y-m-d H:i:s', $event->time ), self::$time_format ); //phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
		$event->next_run_gmt      = date( self::$time_format, $event->time ); //phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
		$event->next_run_relative = self::interval( $event->time - time() );

		return $event;
	}

	/**
	 * Fetches an array of scheduled cron events.
	 *
	 * @return array|WP_Error An array of event objects, or a WP_Error object if there are no events scheduled.
	 */
	protected static function get_cron_events( $is_due_now = false ) {

		// wp_get_ready_cron_jobs since 5.1.0
		if ( $is_due_now && function_exists( 'wp_get_ready_cron_jobs' ) ) {
			$crons = wp_get_ready_cron_jobs();
		} else {
			$crons = _get_cron_array();
		}

		$events = array();

		if ( empty( $crons ) && ! $is_due_now ) {
			return new WP_Error(
				'no_events',
				'You currently have no scheduled cron events.'
			);
		}

		foreach ( $crons as $time => $hooks ) {

			// Incorrectly registered cron events can produce a string key.
			if ( is_string( $time ) ) {
				WP_CLI::warning( sprintf( 'Ignoring incorrectly registered cron event "%s".', $time ) );
				continue;
			}

			foreach ( $hooks as $hook => $hook_events ) {
				foreach ( $hook_events as $sig => $data ) {

					$events[] = (object) array(
						'hook'     => $hook,
						'time'     => $time,
						'sig'      => $sig,
						'args'     => $data['args'],
						'schedule' => $data['schedule'],
						'interval' => Utils\get_flag_value( $data, 'interval' ),
					);

				}
			}
		}

		$events = array_map( 'Cron_Event_Command::format_event', $events );

		return $events;
	}

	/**
	 * Fetches an array of scheduled cron events selected by the user.
	 *
	 * @param array $args       A list of event names
	 * @param array $assoc_args An associative list of CLI parameters
	 *
	 * @return array|WP_Error An array of objects, or a WP_Error object is there are no events scheduled.
	 */
	protected static function get_selected_cron_events( $args, $assoc_args ) {
		$due_now = Utils\get_flag_value( $assoc_args, 'due-now' );
		$all     = Utils\get_flag_value( $assoc_args, 'all' );

		/**
		 * @var string $exclude
		 */
		$exclude = Utils\get_flag_value( $assoc_args, 'exclude' );

		if ( empty( $args ) && ! $due_now && ! $all ) {
			WP_CLI::error( 'Please specify one or more cron events, or use --due-now/--all.' );
		}

		if ( ! empty( $args ) && $all ) {
			WP_CLI::error( 'Please either specify cron events, or use --all.' );
		}

		if ( $due_now && $all ) {
			WP_CLI::error( 'Please use either --due-now or --all.' );
		}

		$events = self::get_cron_events();

		if ( is_wp_error( $events ) ) {
			return $events;
		}

		$hooks = wp_list_pluck( $events, 'hook' );
		foreach ( $args as $hook ) {
			if ( ! in_array( $hook, $hooks, true ) ) {
				WP_CLI::error( sprintf( "Invalid cron event '%s'", $hook ) );
			}
		}

		// Remove all excluded hooks.
		if ( ! empty( $exclude ) ) {
			$exclude = explode( ',', $exclude );
			$events  = array_filter(
				$events,
				function ( $event ) use ( $exclude ) {
					return ! in_array( $event->hook, $exclude, true );
				}
			);
		}

		// If --due-now is specified, take only the events that have 'now' as
		// their next_run_relative time.
		if ( $due_now ) {
			$due_events = array();
			foreach ( $events as $event ) {
				if ( ! empty( $args ) && ! in_array( $event->hook, $args, true ) ) {
					continue;
				}
				if ( 'now' === $event->next_run_relative ) {
					$due_events[] = $event;
				}
			}
			$events = $due_events;
		} elseif ( ! $all ) {
			// If --all is not specified, take only the events that have been
			// given as $args.
			$due_events = array();
			foreach ( $events as $event ) {
				if ( in_array( $event->hook, $args, true ) ) {
					$due_events[] = $event;
				}
			}
			$events = $due_events;
		}

		return $events;
	}

	/**
	 * Converts a time interval into human-readable format.
	 *
	 * Similar to WordPress' built-in `human_time_diff()` but returns two time period chunks instead of just one.
	 *
	 * @param int $since An interval of time in seconds
	 * @return string The interval in human readable format
	 */
	private static function interval( $since ) {
		if ( $since <= 0 ) {
			return 'now';
		}

		$since = absint( $since );

		// Array of time period chunks.
		$chunks = array(
			array( 60 * 60 * 24 * 365, 'year' ),
			array( 60 * 60 * 24 * 30, 'month' ),
			array( 60 * 60 * 24 * 7, 'week' ),
			array( 60 * 60 * 24, 'day' ),
			array( 60 * 60, 'hour' ),
			array( 60, 'minute' ),
			array( 1, 'second' ),
		);

		$name    = 'second';
		$count   = 0;
		$seconds = 1;

		// we only want to output two chunks of time here, eg:
		// x years, xx months
		// x days, xx hours
		// so there's only two bits of calculation below:

		$i = 0;
		$j = 0;

		// step one: the first chunk
		for ( $i = 0, $j = count( $chunks ); $i < $j; $i++ ) {
			$seconds = $chunks[ $i ][0];
			$name    = $chunks[ $i ][1];

			// Finding the biggest chunk (if the chunk fits, break).
			$count = floor( $since / $seconds );
			if ( floatval( 0 ) !== $count ) {
				break;
			}
		}

		// Set output var.
		$output = sprintf( '%d %s', $count, Utils\pluralize( $name, absint( $count ) ) );

		// Step two: the second chunk.
		if ( $i + 1 < $j ) {
			$seconds2 = $chunks[ $i + 1 ][0];
			$name2    = $chunks[ $i + 1 ][1];

			$count2 = floor( ( $since - ( $seconds * $count ) ) / $seconds2 );
			if ( floatval( 0 ) !== $count2 ) {
				// Add to output var.
				$output .= ' ' . sprintf( '%d %s', $count2, Utils\pluralize( $name2, absint( $count2 ) ) );
			}
		}

		return $output;
	}

	private function get_formatter( &$assoc_args ) {
		return new \WP_CLI\Formatter( $assoc_args, $this->fields, 'event' );
	}
}
