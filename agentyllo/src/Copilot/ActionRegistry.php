<?php
/**
 * Copilot safe-action registry — the single source of truth for what the
 * assistant may do inside wp-admin.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Copilot;

use Agentyllo\Infra\Caps;

defined( 'ABSPATH' ) || exit;

/**
 * Every action is a declarative record: id, description, JSON-Schema-like
 * args (type, required, enum, maxlen), the Agentyllo capability it needs,
 * whether it is destructive (needs a signed confirmation), a `run` callable
 * and an optional `dry_run` callable that describes the effect without
 * applying it. The slash parser, the (future) AI tool-calling, the REST
 * validation and the auto-generated Help page all read this one registry —
 * they can never drift apart. Addons register through `agyl_copilot_actions`.
 * The registry executes ONLY predefined, schema-validated operations: no
 * arbitrary code, no raw SQL, no arbitrary options.
 */
final class ActionRegistry {

	/**
	 * Actions keyed by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $actions = array();

	/**
	 * Whether the filter has run.
	 */
	private bool $filtered = false;

	/**
	 * Register an action.
	 *
	 * @param array<string, mixed> $action Action record (id, description, args, cap, destructive, run, dry_run, group).
	 */
	public function register( array $action ): void {
		$id = (string) ( $action['id'] ?? '' );
		if ( '' === $id || ! is_callable( $action['run'] ?? null ) ) {
			return;
		}
		$this->actions[ $id ] = array_merge(
			array(
				'description' => '',
				'group'       => 'general',
				'args'        => array(),
				'cap'         => 'agyl_use_copilot',
				'destructive' => false,
				'dry_run'     => null,
			),
			$action
		);
	}

	/**
	 * All actions (after the filter).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all(): array {
		if ( ! $this->filtered ) {
			$this->filtered = true;
			/**
			 * Filter/extend the copilot action registry.
			 *
			 * @param ActionRegistry $registry Registry (call register()).
			 */
			do_action( 'agyl_copilot_actions', $this );
		}

		return $this->actions;
	}

	/**
	 * One action or null.
	 *
	 * @param string $id Action id.
	 * @return array<string, mixed>|null
	 */
	public function get( string $id ): ?array {
		return $this->all()[ $id ] ?? null;
	}

	/**
	 * Client-safe descriptions (Help page, parser hints): no callables.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function describe(): array {
		$out = array();
		foreach ( $this->all() as $id => $action ) {
			$out[] = array(
				'id'          => $id,
				'group'       => (string) $action['group'],
				'description' => (string) $action['description'],
				'args'        => (array) $action['args'],
				'destructive' => (bool) $action['destructive'],
				'cap'         => (string) $action['cap'],
				'usage'       => self::usage( $id, (array) $action['args'] ),
			);
		}
		usort( $out, static fn ( array $a, array $b ): int => strcmp( $a['group'] . $a['id'], $b['group'] . $b['id'] ) );

		return $out;
	}

	/**
	 * Validate + coerce args against the action schema. Returns
	 * [args, errors]; errors is empty when valid.
	 *
	 * @param string               $id  Action id.
	 * @param array<string, mixed> $raw Raw args.
	 * @return array{0: array<string, mixed>, 1: string[]}
	 */
	public function validate( string $id, array $raw ): array {
		$action = $this->get( $id );
		if ( null === $action ) {
			return array( array(), array( __( 'Unknown action.', 'agentyllo' ) ) );
		}
		$clean  = array();
		$errors = array();
		foreach ( (array) $action['args'] as $name => $spec ) {
			$has = array_key_exists( $name, $raw ) && '' !== $raw[ $name ] && null !== $raw[ $name ];
			if ( ! $has ) {
				if ( ! empty( $spec['required'] ) ) {
					$errors[] = sprintf(
						/* translators: %s: argument name */
						__( 'Missing argument: %s', 'agentyllo' ),
						$name
					);
				} elseif ( array_key_exists( 'default', $spec ) ) {
					$clean[ $name ] = $spec['default'];
				}
				continue;
			}
			$value = $raw[ $name ];
			switch ( (string) ( $spec['type'] ?? 'string' ) ) {
				case 'int':
					if ( ! is_numeric( $value ) ) {
						$errors[] = sprintf(
							/* translators: %s: argument name */
							__( 'Argument %s must be a number.', 'agentyllo' ),
							$name
						);
						continue 2;
					}
					$int = (int) $value;
					if ( isset( $spec['min'] ) ) {
						$int = max( (int) $spec['min'], $int );
					}
					if ( isset( $spec['max'] ) ) {
						$int = min( (int) $spec['max'], $int );
					}
					$clean[ $name ] = $int;
					break;
				case 'bool':
					$clean[ $name ] = rest_sanitize_boolean( $value );
					break;
				case 'enum':
					if ( ! in_array( $value, (array) ( $spec['values'] ?? array() ), true ) ) {
						$errors[] = sprintf(
							/* translators: 1: argument name, 2: allowed values */
							__( 'Argument %1$s must be one of: %2$s', 'agentyllo' ),
							$name,
							implode( ', ', (array) ( $spec['values'] ?? array() ) )
						);
						continue 2;
					}
					$clean[ $name ] = $value;
					break;
				case 'text':
					$text = is_scalar( $value ) ? sanitize_textarea_field( (string) $value ) : '';
					$clean[ $name ] = isset( $spec['maxlen'] ) ? mb_substr( $text, 0, (int) $spec['maxlen'] ) : $text;
					break;
				default:
					$text = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
					$clean[ $name ] = isset( $spec['maxlen'] ) ? mb_substr( $text, 0, (int) $spec['maxlen'] ) : $text;
			}
		}

		return array( $clean, $errors );
	}

	/**
	 * Whether the current user may run an action.
	 *
	 * @param string $id Action id.
	 */
	public function allowed( string $id ): bool {
		$action = $this->get( $id );

		return null !== $action && Caps::can( (string) $action['cap'] );
	}

	/**
	 * Dry-run description (never mutates). Returns a human summary + diff-ish
	 * array for the proposal card.
	 *
	 * @param string               $id   Action id.
	 * @param array<string, mixed> $args Validated args.
	 * @return array{summary: string, details: array}
	 */
	public function dry_run( string $id, array $args ): array {
		$action = $this->get( $id );
		if ( null === $action ) {
			return array(
				'summary' => __( 'Unknown action.', 'agentyllo' ),
				'details' => array(),
			);
		}
		if ( is_callable( $action['dry_run'] ) ) {
			$result = ( $action['dry_run'] )( $args );
			if ( is_array( $result ) ) {
				return array(
					'summary' => (string) ( $result['summary'] ?? '' ),
					'details' => (array) ( $result['details'] ?? array() ),
				);
			}
		}

		return array(
			'summary' => (string) $action['description'],
			'details' => $args,
		);
	}

	/**
	 * Execute (caller has validated, checked capability and confirmation).
	 *
	 * @param string               $id   Action id.
	 * @param array<string, mixed> $args Validated args.
	 * @return array{ok: bool, message: string, data: array}
	 */
	public function run( string $id, array $args ): array {
		$action = $this->get( $id );
		if ( null === $action ) {
			return array(
				'ok'      => false,
				'message' => __( 'Unknown action.', 'agentyllo' ),
				'data'    => array(),
			);
		}
		try {
			$result = ( $action['run'] )( $args );
		} catch ( \Throwable $e ) {
			return array(
				'ok'      => false,
				'message' => $e->getMessage(),
				'data'    => array(),
			);
		}
		if ( ! is_array( $result ) ) {
			return array(
				'ok'      => (bool) $result,
				'message' => '',
				'data'    => array(),
			);
		}

		return array(
			'ok'      => (bool) ( $result['ok'] ?? true ),
			'message' => (string) ( $result['message'] ?? '' ),
			'data'    => (array) ( $result['data'] ?? array() ),
		);
	}

	/**
	 * Slash usage line for an action.
	 *
	 * @param string               $id   Action id.
	 * @param array<string, mixed> $args Arg schema.
	 */
	public static function usage( string $id, array $args ): string {
		[ $group, $verb ] = array_pad( explode( '.', $id, 2 ), 2, '' );
		$parts            = array();
		foreach ( $args as $name => $spec ) {
			$token   = $name . ':' . ( 'enum' === ( $spec['type'] ?? '' ) ? implode( '|', (array) ( $spec['values'] ?? array() ) ) : ( 'int' === ( $spec['type'] ?? '' ) ? 'N' : '"…"' ) );
			$parts[] = ! empty( $spec['required'] ) ? $token : '[' . $token . ']';
		}

		return '/' . $group . ' ' . str_replace( '_', '-', $verb ) . ( $parts ? ' ' . implode( ' ', $parts ) : '' );
	}
}
