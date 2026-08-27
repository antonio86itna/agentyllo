<?php
/**
 * Shared tokenizer for indexing and querying.
 *
 * Immune to hosting FULLTEXT quirks (min token size, CJK breakage): the
 * inverted index in agyl_kb_terms is built from THESE tokens on both sides.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\KB\Retrieval;

defined( 'ABSPATH' ) || exit;

/**
 * Lowercase → unicode word segmentation → CJK bigrams → stopword removal →
 * light suffix stemming. Dynamic stopwords (terms in >20% of the corpus,
 * recomputed nightly) come from the `agyl_kb_dynamic_stopwords` option.
 */
final class Tokenizer {

	private const MIN_LEN = 2;
	private const MAX_LEN = 48;

	/**
	 * Static stopword lists per language (top function words only — small on
	 * purpose; the dynamic corpus-based list does the heavy lifting).
	 *
	 * @var array<string, string[]>
	 */
	private const STOPWORDS = array(
		'en' => array( 'the', 'and', 'for', 'are', 'was', 'were', 'this', 'that', 'with', 'from', 'have', 'has', 'had', 'not', 'but', 'you', 'your', 'our', 'can', 'will', 'all', 'any', 'its', 'his', 'her', 'they', 'them', 'been', 'more', 'other', 'into', 'than', 'then', 'when', 'what', 'which', 'who', 'how', 'out', 'about', 'also', 'to', 'of', 'in', 'on', 'at', 'is', 'it', 'as', 'be', 'by', 'do', 'does', 'did', 'or', 'if', 'so', 'up', 'an', 'we', 'us', 'me', 'my', 'am', 'i', 'a', 'no', 'yes', 'get', 'got', 'there', 'here', 'where', 'why', 'much', 'many', 'some', 'would', 'could', 'should', 'please', 'want', 'need', 'like', 'know', 'tell', 'give', 'show', 'find', 'just', 'very', 'really' ),
		'it' => array( 'il', 'lo', 'la', 'le', 'gli', 'un', 'una', 'uno', 'di', 'da', 'in', 'su', 'per', 'con', 'tra', 'fra', 'che', 'chi', 'cui', 'non', 'come', 'dove', 'quando', 'anche', 'ancora', 'della', 'delle', 'dello', 'degli', 'del', 'dei', 'nel', 'nella', 'sono', 'essere', 'questo', 'questa', 'questi', 'queste', 'quello', 'più', 'molto', 'tutto', 'tutti', 'al', 'ai', 'agli', 'alla', 'alle', 'allo', 'e', 'o', 'ma', 'se', 'si', 'ci', 'vi', 'ne', 'mi', 'ti', 'lui', 'lei', 'noi', 'voi', 'loro', 'ho', 'hai', 'ha', 'è', 'era', 'sia', 'quanto', 'quanti', 'quale', 'quali', 'cosa', 'vorrei', 'voglio', 'posso', 'puoi', 'può', 'grazie', 'prego', 'ecco' ),
		'de' => array( 'der', 'die', 'das', 'den', 'dem', 'des', 'ein', 'eine', 'einen', 'einem', 'und', 'oder', 'aber', 'mit', 'von', 'für', 'auf', 'aus', 'bei', 'nach', 'ist', 'sind', 'war', 'nicht', 'auch', 'wenn', 'wie', 'wir', 'sie', 'ich', 'sich', 'werden', 'wurde', 'kann', 'haben', 'hat', 'zu', 'im', 'am', 'an', 'in', 'es', 'er', 'du', 'ihr', 'mir', 'mich', 'uns', 'euch', 'was', 'wo', 'wer', 'wann', 'warum', 'noch', 'schon', 'sehr', 'bitte', 'danke', 'können', 'möchte', 'kein', 'keine', 'diese', 'dieser', 'dieses' ),
		'fr' => array( 'le', 'la', 'les', 'un', 'une', 'des', 'du', 'de', 'et', 'ou', 'mais', 'dans', 'sur', 'pour', 'avec', 'par', 'pas', 'est', 'sont', 'que', 'qui', 'quoi', 'dont', 'nous', 'vous', 'ils', 'elles', 'être', 'avoir', 'cette', 'ces', 'son', 'ses', 'leur', 'plus', 'tout', 'tous', 'à', 'au', 'aux', 'en', 'y', 'je', 'tu', 'il', 'elle', 'on', 'me', 'te', 'se', 'ne', 'ce', 'cet', 'ma', 'mon', 'mes', 'votre', 'vos', 'où', 'quand', 'comment', 'pourquoi', 'combien', 'quel', 'quelle', 'quels', 'quelles', 'très', 'aussi', 'merci', 'bonjour', 'voudrais', 'peux', 'peut', 'faut' ),
		'es' => array( 'el', 'la', 'los', 'las', 'un', 'una', 'unos', 'unas', 'de', 'del', 'en', 'por', 'para', 'con', 'sin', 'que', 'como', 'donde', 'cuando', 'y', 'o', 'pero', 'no', 'es', 'son', 'está', 'están', 'ser', 'estar', 'este', 'esta', 'estos', 'estas', 'ese', 'esa', 'más', 'todo', 'todos', 'a', 'al', 'lo', 'le', 'les', 'se', 'me', 'te', 'mi', 'tu', 'su', 'sus', 'yo', 'él', 'ella', 'nos', 'hay', 'muy', 'qué', 'cuál', 'cuánto', 'cómo', 'dónde', 'quiero', 'puedo', 'puede', 'tengo', 'tiene', 'gracias', 'hola', 'por favor', 'sí' ),
		'pt' => array( 'o', 'a', 'os', 'as', 'um', 'uma', 'uns', 'umas', 'de', 'do', 'da', 'dos', 'das', 'em', 'no', 'na', 'nos', 'nas', 'por', 'para', 'com', 'sem', 'que', 'como', 'quando', 'e', 'ou', 'mas', 'não', 'é', 'são', 'ser', 'estar', 'este', 'esta', 'esse', 'essa', 'mais', 'todo', 'todos', 'ao', 'aos', 'à', 'às', 'se', 'me', 'te', 'meu', 'minha', 'seu', 'sua', 'eu', 'ele', 'ela', 'você', 'vocês', 'muito', 'qual', 'quanto', 'onde', 'quero', 'posso', 'pode', 'tenho', 'tem', 'obrigado', 'obrigada', 'olá', 'sim' ),
		'nl' => array( 'de', 'het', 'een', 'en', 'of', 'maar', 'van', 'voor', 'met', 'op', 'in', 'aan', 'bij', 'naar', 'uit', 'is', 'zijn', 'was', 'niet', 'ook', 'als', 'dat', 'die', 'dit', 'deze', 'wij', 'zij', 'je', 'hebben', 'heeft', 'kan', 'worden', 'meer', 'alle', 'te', 'er', 'ik', 'jij', 'u', 'we', 'ze', 'hij', 'mij', 'me', 'ons', 'jullie', 'wat', 'waar', 'hoe', 'wanneer', 'waarom', 'welke', 'wel', 'nog', 'graag', 'kunnen', 'kunt', 'wil', 'moet', 'bedankt', 'hallo', 'ja', 'nee' ),
	);

	/**
	 * Light suffix stemming rules per language (longest-first).
	 *
	 * @var array<string, string[]>
	 */
	private const SUFFIXES = array(
		'en' => array( 'ingly', 'edly', 'ing', 'ies', 'ied', 'ed', 'es', 's' ),
		'it' => array( 'azione', 'azioni', 'mente', 'ando', 'endo', 'ato', 'ata', 'ati', 'ate', 'are', 'ere', 'ire', 'i', 'e', 'a', 'o' ),
		'de' => array( 'ungen', 'ung', 'heit', 'keit', 'lich', 'isch', 'en', 'er', 'es', 'e', 'n' ),
		'fr' => array( 'ations', 'ation', 'ement', 'euses', 'euse', 'eurs', 'eur', 'es', 'er', 's', 'e' ),
		'es' => array( 'aciones', 'ación', 'mente', 'ando', 'iendo', 'ado', 'ada', 'ados', 'adas', 'ar', 'er', 'ir', 'es', 's' ),
		'pt' => array( 'ações', 'ação', 'mente', 'ando', 'endo', 'ado', 'ada', 'ados', 'adas', 'ar', 'er', 'ir', 'es', 's' ),
		'nl' => array( 'heden', 'heid', 'ingen', 'ing', 'en', 'er', 'e', 's' ),
	);

	/**
	 * Cached dynamic stopwords.
	 *
	 * @var string[]|null
	 */
	private ?array $dynamic_stopwords = null;

	/**
	 * Term frequencies for a text.
	 *
	 * @param string $text Raw text.
	 * @param string $lang Locale or language code ('it_IT' or 'it').
	 * @return array<string, int> term => tf.
	 */
	public function terms( string $text, string $lang = '' ): array {
		$tf = array();
		foreach ( $this->tokenize( $text, $lang ) as $term ) {
			$tf[ $term ] = ( $tf[ $term ] ?? 0 ) + 1;
		}

		return $tf;
	}

	/**
	 * Ordered token list (query side keeps order for phrase heuristics).
	 *
	 * @param string $text Raw text.
	 * @param string $lang Locale or language code.
	 * @return string[]
	 */
	public function tokenize( string $text, string $lang = '' ): array {
		$lang = strtolower( substr( $lang ?: (string) get_locale(), 0, 2 ) );
		$text = mb_strtolower( $text, 'UTF-8' );

		// Split into unicode word runs (letters, numbers, joined by internal '-'/'.').
		preg_match_all( '/[\p{L}\p{N}]+(?:[\'\-.][\p{L}\p{N}]+)*/u', $text, $matches );

		$stop    = array_flip( self::STOPWORDS[ $lang ] ?? array() );
		$dynamic = array_flip( $this->dynamic_stopwords() );
		$out     = array();

		foreach ( $matches[0] as $word ) {
			foreach ( $this->expand( $word ) as $token ) {
				$len = mb_strlen( $token, 'UTF-8' );
				if ( $len < self::MIN_LEN || $len > self::MAX_LEN ) {
					continue;
				}
				if ( isset( $stop[ $token ] ) || isset( $dynamic[ $token ] ) ) {
					continue;
				}
				$out[] = $this->stem( $token, $lang );
			}
		}

		return $out;
	}

	/**
	 * CJK runs become character bigrams; everything else passes through.
	 *
	 * @param string $word Word run.
	 * @return string[]
	 */
	private function expand( string $word ): array {
		if ( ! preg_match( '/[\x{3040}-\x{30FF}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{AC00}-\x{D7AF}]/u', $word ) ) {
			return array( $word );
		}

		$chars = preg_split( '//u', $word, -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $chars || count( $chars ) < 2 ) {
			return array( $word );
		}

		$bigrams = array();
		$total   = count( $chars ) - 1;
		for ( $i = 0; $i < $total; $i++ ) {
			$bigrams[] = $chars[ $i ] . $chars[ $i + 1 ];
		}

		return $bigrams;
	}

	/**
	 * Light suffix stripping: never below 4 chars, one suffix max.
	 *
	 * @param string $token Token.
	 * @param string $lang  Two-letter language.
	 */
	private function stem( string $token, string $lang ): string {
		foreach ( self::SUFFIXES[ $lang ] ?? array() as $suffix ) {
			$slen = strlen( $suffix );
			if ( strlen( $token ) - $slen >= 4 && str_ends_with( $token, $suffix ) ) {
				return substr( $token, 0, -$slen );
			}
		}

		return $token;
	}

	/**
	 * Corpus-derived stopwords (terms in >20% of chunks), refreshed nightly
	 * by the KB health job.
	 *
	 * @return string[]
	 */
	private function dynamic_stopwords(): array {
		if ( null === $this->dynamic_stopwords ) {
			$stored                  = get_option( 'agyl_kb_dynamic_stopwords', array() );
			$this->dynamic_stopwords = is_array( $stored ) ? array_map( 'strval', $stored ) : array();
		}

		return $this->dynamic_stopwords;
	}
}
