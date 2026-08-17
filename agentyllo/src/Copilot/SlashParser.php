<?php
/**
 * Classic (no-LLM) command parser for the copilot.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Copilot;

defined( 'ABSPATH' ) || exit;

/**
 * Grammar: `/group verb key:"quoted value" key:value …`. Verbs map to
 * registry ids as group.verb (dashes → underscores): `/kb add title:"Hours"
 * content:"…"` → kb.add_entry (verb aliases below), `/settings set tab:general
 * key:tone value:playful`, `/memory teach fact:"…"`, `/stats query
 * period:30d`, `/help`. Pure PHP; unit-testable.
 */
final class SlashParser {

	private const ALIASES = array(
		'kb.add'    => 'kb.add_entry',
		'kb.new'    => 'kb.add_entry',
		'kb.edit'   => 'kb.update',
		'kb.remove' => 'kb.delete',
		'kb.trash'  => 'kb.delete',
		'kb.undo'   => 'kb.restore',
		'kb.ls'     => 'kb.list',
		'kb.crawl'  => 'kb.reindex',
		'stats.show' => 'stats.query',
		'memory.list' => 'memory.query',
		'memory.show' => 'memory.query',
	);

	/**
	 * Parse a message. Returns null when it is not a slash command; otherwise
	 * {action, args, help}.
	 *
	 * @param string $text Raw text.
	 * @return array{action: string, args: array<string, string>, help: bool}|null
	 */
	public static function parse( string $text ): ?array {
		$text = trim( $text );
		if ( '' === $text || '/' !== $text[0] ) {
			return null;
		}
		if ( preg_match( '/^\/help\b/i', $text ) ) {
			return array(
				'action' => '',
				'args'   => array(),
				'help'   => true,
			);
		}
		if ( ! preg_match( '/^\/([a-z]+)\s+([a-z\-_]+)\s*(.*)$/isu', $text, $m ) ) {
			return array(
				'action' => '',
				'args'   => array(),
				'help'   => false,
			);
		}
		$group  = strtolower( $m[1] );
		$verb   = str_replace( '-', '_', strtolower( $m[2] ) );
		$action = self::ALIASES[ $group . '.' . $verb ] ?? ( $group . '.' . $verb );

		return array(
			'action' => $action,
			'args'   => self::parse_args( (string) $m[3] ),
			'help'   => false,
		);
	}

	/**
	 * key:"quoted", key:'quoted', key:bare, plus a trailing bare remainder
	 * assigned to `content`/`fact`/`value` when the action expects it.
	 *
	 * @param string $rest Argument string.
	 * @return array<string, string>
	 */
	public static function parse_args( string $rest ): array {
		$args = array();
		$rest = trim( $rest );
		if ( '' === $rest ) {
			return $args;
		}
		$pattern = '/([a-z_]+):(?:"((?:[^"\\\\]|\\\\.)*)"|\'((?:[^\'\\\\]|\\\\.)*)\'|(\S+))/iu';
		if ( preg_match_all( $pattern, $rest, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			$consumed = 0;
			foreach ( $matches as $match ) {
				$key   = strtolower( $match[1][0] );
				$value = '' !== ( $match[2][0] ?? '' ) ? $match[2][0] : ( '' !== ( $match[3][0] ?? '' ) ? $match[3][0] : ( $match[4][0] ?? '' ) );
				$args[ $key ] = stripcslashes( (string) $value );
				$consumed     = max( $consumed, $match[0][1] + strlen( $match[0][0] ) );
			}
			$tail = trim( substr( $rest, $consumed ) );
			if ( '' !== $tail && ! isset( $args['_tail'] ) ) {
				$args['_tail'] = $tail;
			}
		} else {
			$args['_tail'] = $rest;
		}

		return $args;
	}
}
