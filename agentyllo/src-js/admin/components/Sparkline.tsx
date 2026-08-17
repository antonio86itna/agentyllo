/**
 * Dependency-free SVG sparkline (accessible: title + aria-label summary).
 */
export default function Sparkline( {
	values,
	label,
	width = 160,
	height = 36,
	color = 'currentColor',
}: {
	values: number[];
	label: string;
	width?: number;
	height?: number;
	color?: string;
} ) {
	if ( ! values.length ) {
		return <span className="agy-muted">—</span>;
	}
	const max = Math.max( 1, ...values );
	const step = values.length > 1 ? width / ( values.length - 1 ) : width;
	const points = values
		.map( ( v, i ) => `${ ( i * step ).toFixed( 1 ) },${ ( height - ( v / max ) * ( height - 4 ) - 2 ).toFixed( 1 ) }` )
		.join( ' ' );
	const summary = `${ label }: ${ values.join( ', ' ) }`;

	return (
		<svg
			className="agy-sparkline"
			width={ width }
			height={ height }
			viewBox={ `0 0 ${ width } ${ height }` }
			role="img"
			aria-label={ summary }
		>
			<title>{ summary }</title>
			<polyline fill="none" stroke={ color } strokeWidth="2" strokeLinejoin="round" strokeLinecap="round" points={ points } />
		</svg>
	);
}
