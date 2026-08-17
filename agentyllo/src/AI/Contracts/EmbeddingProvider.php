<?php
/**
 * Embedding provider contract.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\AI\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Dense (OpenAI text-embedding-3, ONNX e5) or sparse (TF-IDF: dimensions 0)
 * embeddings behind one interface so retrieval stays provider-agnostic.
 */
interface EmbeddingProvider {

	/**
	 * Stable id: 'openai_te3s' | 'onnx_e5_small' | 'tfidf' | …
	 */
	public function id(): string;

	/**
	 * Configured + usable. Cheap; no network.
	 */
	public function is_available(): bool;

	/**
	 * Vector size (0 = sparse).
	 */
	public function dimensions(): int;

	/**
	 * Whether the model handles many languages in one space.
	 */
	public function is_multilingual(): bool;

	/**
	 * Embed a batch. Returns float[][] aligned with input; empty array on failure.
	 *
	 * @param string[] $texts Inputs.
	 * @return array<int, float[]>
	 */
	public function embed( array $texts ): array;
}
