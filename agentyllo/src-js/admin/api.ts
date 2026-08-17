/**
 * apiFetch wrapper that prefixes the Agentyllo REST namespace. The root URL
 * comes from rest_url() (works with plain and pretty permalinks).
 */
import apiFetch from '@wordpress/api-fetch';

const NS = '/agentyllo/v1';

export function api< T = any >(
	options: { path: string } & Record< string, any >
): Promise< T > {
	return apiFetch( { ...options, path: NS + options.path } ) as Promise< T >;
}
