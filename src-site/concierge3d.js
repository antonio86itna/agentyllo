/**
 * Agentyllo concierge — living 3D mascot.
 *
 * The Meshy GLB is a single static mesh, so this module builds a procedural
 * rig at runtime: vertices are weighted to bones (head / arms / spine) by
 * region, the baked eyes were removed from the texture and are redrawn on a
 * dynamic canvas (blink / wink / happy / smile / surprised), and simple prop
 * meshes (laptop, coffee) appear in its hands. A state machine loops random
 * behaviours: idle, sway, fly left/right, wave, tilt, props.
 *
 * Modes: hero (full show), point (points at a card below), peek (half bust
 * behind a container edge, micro-movements only).
 */
import {
	ACESFilmicToneMapping, AmbientLight, Bone, BoxGeometry, CanvasTexture,
	Clock, Color, CylinderGeometry, DirectionalLight, Float32BufferAttribute,
	Group, Mesh, MeshBasicMaterial, MeshStandardMaterial, PerspectiveCamera,
	PlaneGeometry, PointLight, Scene, Skeleton, SkinnedMesh, SRGBColorSpace,
	Uint16BufferAttribute, WebGLRenderer,
} from 'three';
import { GLTFLoader } from 'three/examples/jsm/loaders/GLTFLoader.js';

const MODEL_URL = '/assets/model/concierge2.glb';

/* ------------------------------------------------------------------ */
/* Face texture controller                                            */
/* ------------------------------------------------------------------ */
// Face panel island in the 2048 texture. The island is rotated: the face's
// horizontal axis runs down the texture's Y axis.
const FACE = { cx: 187, cy: 568, halfW: 200, halfH: 140 }; // face-space halfW along ty, halfH along tx
const CHEST = { cx: 535, cy: 1848, size: 44 };
const EYE_COL = '#b7c1ff';
const GLOW = '#6d79ff';

// face-space (fx right, fy down as seen on the robot) -> texture px.
// Calibrated: island rotated 90° and v-flipped.
let FMAP = ( fx, fy ) => [ FACE.cx - fy, FACE.cy - fx ];

class FaceCtl {
	constructor( baseImage, material ) {
		this.canvas = document.createElement( 'canvas' );
		this.canvas.width = 2048; this.canvas.height = 2048;
		this.ctx = this.canvas.getContext( '2d' );
		this.base = baseImage;
		this.ctx.drawImage( baseImage, 0, 0 );

		this.tex = new CanvasTexture( this.canvas );
		this.tex.flipY = false;
		this.tex.colorSpace = SRGBColorSpace;
		material.map = this.tex;

		// emissive: black canvas + glowing features
		this.eCanvas = document.createElement( 'canvas' );
		this.eCanvas.width = 1024; this.eCanvas.height = 1024;
		this.eCtx = this.eCanvas.getContext( '2d' );
		this.eTex = new CanvasTexture( this.eCanvas );
		this.eTex.flipY = false;
		this.eTex.colorSpace = SRGBColorSpace;
		material.emissive = new Color( 0xffffff );
		material.emissiveMap = this.eTex;
		material.emissiveIntensity = 1.0;
		material.needsUpdate = true;

		this.expr = 'normal';
		this.draw( 'normal' );
	}

	line( ctx, s, pts, w ) {
		ctx.beginPath();
		pts.forEach( ( [ fx, fy ], i ) => {
			const [ tx, ty ] = FMAP( fx, fy );
			i ? ctx.lineTo( tx * s, ty * s ) : ctx.moveTo( tx * s, ty * s );
		} );
		ctx.lineWidth = w * s;
		ctx.lineCap = 'round';
		ctx.lineJoin = 'round';
		ctx.stroke();
	}

	// arc in face space: center, radius, from..to angles (0=right, CCW)
	arcPts( cx, cy, r, a0, a1, n = 14 ) {
		const pts = [];
		for ( let i = 0; i <= n; i++ ) {
			const a = a0 + ( a1 - a0 ) * ( i / n );
			pts.push( [ cx + Math.cos( a ) * r, cy - Math.sin( a ) * r ] );
		}
		return pts;
	}

	paint( ctx, s, expr ) {
		ctx.save();
		ctx.strokeStyle = EYE_COL;
		ctx.fillStyle = EYE_COL;
		ctx.shadowColor = GLOW;
		ctx.shadowBlur = 26 * s;
		const EX = 62, EY = -6, R = 38, W = 22;
		const eye = ( sign, kind ) => {
			const cx = sign * EX, cy = EY;
			if ( 'arc' === kind ) { // happy ^ arc
				this.line( ctx, s, this.arcPts( cx, cy + 14, R, Math.PI * 0.15, Math.PI * 0.85 ), W );
			} else if ( 'flat' === kind ) {
				this.line( ctx, s, [ [ cx - R, cy ], [ cx + R, cy ] ], W );
			} else if ( 'dot' === kind ) {
				const [ tx, ty ] = FMAP( cx, cy );
				ctx.beginPath(); ctx.arc( tx * s, ty * s, 26 * s, 0, Math.PI * 2 ); ctx.fill();
			} else if ( 'happy' === kind ) {
				this.line( ctx, s, this.arcPts( cx, cy + 20, R + 6, Math.PI * 0.1, Math.PI * 0.9 ), W );
			}
		};
		const mouth = ( kind ) => {
			if ( 'smile' === kind ) {
				this.line( ctx, s, this.arcPts( 0, 46, 44, Math.PI * 1.2, Math.PI * 1.8 ), 15 );
			} else if ( 'o' === kind ) {
				const [ tx, ty ] = FMAP( 0, 60 );
				ctx.beginPath(); ctx.arc( tx * s, ty * s, 18 * s, 0, Math.PI * 2 );
				ctx.lineWidth = 12 * s; ctx.stroke();
			}
		};
		switch ( expr ) {
			case 'blink': eye( -1, 'flat' ); eye( 1, 'flat' ); break;
			case 'wink': eye( -1, 'arc' ); eye( 1, 'flat' ); mouth( 'smile' ); break;
			case 'happy': eye( -1, 'happy' ); eye( 1, 'happy' ); mouth( 'smile' ); break;
			case 'smile': eye( -1, 'arc' ); eye( 1, 'arc' ); mouth( 'smile' ); break;
			case 'surprised': eye( -1, 'dot' ); eye( 1, 'dot' ); mouth( 'o' ); break;
			case 'neutral': eye( -1, 'flat' ); eye( 1, 'flat' ); break;
			default: eye( -1, 'arc' ); eye( 1, 'arc' );
		}
		ctx.restore();
	}

	draw( expr ) {
		if ( expr === this.expr && this._drawn ) return;
		this.expr = expr; this._drawn = true;
		const c = this.ctx;
		c.clearRect( 0, 0, 2048, 2048 );
		c.drawImage( this.base, 0, 0 );
		this.paint( c, 1, expr );
		this.tex.needsUpdate = true;

		const e = this.eCtx, s = 0.5;
		e.fillStyle = '#000';
		e.fillRect( 0, 0, 1024, 1024 );
		this.paint( e, s, expr );
		// chest A-Core always glows softly
		e.save();
		e.strokeStyle = '#8f9bff'; e.fillStyle = '#8f9bff';
		e.shadowColor = GLOW; e.shadowBlur = 10;
		const k = CHEST.size / 48, cx = CHEST.cx * s, cy = CHEST.cy * s;
		const P = ( x, y ) => [ cx + ( x - 24 ) * k * s * 2, cy - ( y - 24 ) * k * s * 2 ];
		e.lineWidth = 3.4 * k; e.lineCap = 'round';
		[ [ [ 11.4, 41 ], [ 24, 9.5 ] ], [ [ 36.6, 41 ], [ 24, 9.5 ] ], [ [ 16.9, 31.5 ], [ 31.1, 31.5 ] ] ].forEach( ( [ a, b ] ) => {
			e.beginPath(); e.moveTo( ...P( ...a ) ); e.lineTo( ...P( ...b ) ); e.stroke();
		} );
		[ [ 16.9, 31.5, 2.6 ], [ 31.1, 31.5, 2.6 ], [ 24, 9.5, 4.2 ] ].forEach( ( [ x, y, r ] ) => {
			const p = P( x, y );
			e.beginPath(); e.arc( p[ 0 ], p[ 1 ], r * k, 0, Math.PI * 2 ); e.fill();
		} );
		e.restore();
		this.eTex.needsUpdate = true;
	}
}

/* ------------------------------------------------------------------ */
/* Procedural rig                                                     */
/* ------------------------------------------------------------------ */
// Region thresholds in model space (bbox y ±0.95, x ±0.53).
const RIG = {
	headY: 0.32, headBlend: 0.10,
	armX: 0.285, armBlend: 0.045, armYMax: 0.38,
	shoulderL: [ -0.36, 0.22, 0 ], shoulderR: [ 0.36, 0.22, 0 ],
	headPivot: [ 0, 0.30, 0 ], spinePivot: [ 0, -0.5, 0 ],
};

function buildSkinned( geometry, material ) {
	const pos = geometry.attributes.position;
	const count = pos.count;
	const si = new Uint16Array( count * 4 );
	const sw = new Float32Array( count * 4 );
	// bones: 0 spine, 1 head, 2 armL, 3 armR
	for ( let i = 0; i < count; i++ ) {
		const x = pos.getX( i ), y = pos.getY( i );
		let a = 0, b = 0, t = 0; // bone a=spine default
		if ( y > RIG.headY - RIG.headBlend && Math.abs( x ) < RIG.armX ) {
			b = 1;
			t = Math.min( 1, Math.max( 0, ( y - ( RIG.headY - RIG.headBlend ) ) / ( 2 * RIG.headBlend ) ) );
		} else if ( x < -RIG.armX + RIG.armBlend && y < RIG.armYMax ) {
			b = 2;
			t = Math.min( 1, Math.max( 0, ( -x - ( RIG.armX - RIG.armBlend ) ) / ( 2 * RIG.armBlend ) ) );
		} else if ( x > RIG.armX - RIG.armBlend && y < RIG.armYMax ) {
			b = 3;
			t = Math.min( 1, Math.max( 0, ( x - ( RIG.armX - RIG.armBlend ) ) / ( 2 * RIG.armBlend ) ) );
		}
		t = t * t * ( 3 - 2 * t ); // smoothstep
		si[ i * 4 ] = a; si[ i * 4 + 1 ] = b;
		sw[ i * 4 ] = 1 - t; sw[ i * 4 + 1 ] = t;
	}
	geometry.setAttribute( 'skinIndex', new Uint16BufferAttribute( si, 4 ) );
	geometry.setAttribute( 'skinWeight', new Float32BufferAttribute( sw, 4 ) );

	const spine = new Bone(); spine.position.set( ...RIG.spinePivot );
	const head = new Bone(); head.position.set(
		RIG.headPivot[ 0 ] - RIG.spinePivot[ 0 ], RIG.headPivot[ 1 ] - RIG.spinePivot[ 1 ], 0 );
	const armL = new Bone(); armL.position.set(
		RIG.shoulderL[ 0 ] - RIG.spinePivot[ 0 ], RIG.shoulderL[ 1 ] - RIG.spinePivot[ 1 ], 0 );
	const armR = new Bone(); armR.position.set(
		RIG.shoulderR[ 0 ] - RIG.spinePivot[ 0 ], RIG.shoulderR[ 1 ] - RIG.spinePivot[ 1 ], 0 );
	spine.add( head ); spine.add( armL ); spine.add( armR );

	material.skinning = true;
	const mesh = new SkinnedMesh( geometry, material );
	mesh.add( spine );
	mesh.bind( new Skeleton( [ spine, head, armL, armR ] ) );
	mesh.frustumCulled = false;
	return { mesh, bones: { spine, head, armL, armR } };
}

/* ------------------------------------------------------------------ */
/* Props                                                              */
/* ------------------------------------------------------------------ */
function makeLaptop() {
	const g = new Group();
	const dark = new MeshStandardMaterial( { color: 0x141b2e, roughness: 0.5, metalness: 0.4 } );
	const base = new Mesh( new BoxGeometry( 0.34, 0.018, 0.22 ), dark );
	g.add( base );
	const lid = new Mesh( new BoxGeometry( 0.34, 0.22, 0.014 ), dark );
	lid.position.set( 0, 0.105, -0.115 ); lid.rotation.x = -0.28;
	g.add( lid );
	// screen with glowing A-Core
	const c = document.createElement( 'canvas' ); c.width = 256; c.height = 168;
	const x = c.getContext( '2d' );
	x.fillStyle = '#101736'; x.fillRect( 0, 0, 256, 168 );
	x.strokeStyle = '#9aa5ff'; x.fillStyle = '#9aa5ff';
	x.shadowColor = '#6d79ff'; x.shadowBlur = 14;
	x.lineWidth = 7; x.lineCap = 'round';
	const k = 2.1, ox = 128 - 24 * k, oy = 84 - 25 * k;
	const P = ( px, py ) => [ ox + px * k, oy + py * k ];
	[ [ [ 11.4, 41 ], [ 24, 9.5 ] ], [ [ 36.6, 41 ], [ 24, 9.5 ] ], [ [ 16.9, 31.5 ], [ 31.1, 31.5 ] ] ].forEach( ( [ a, b ] ) => {
		x.beginPath(); x.moveTo( ...P( ...a ) ); x.lineTo( ...P( ...b ) ); x.stroke();
	} );
	x.beginPath(); x.arc( ...P( 24, 9.5 ), 4.4 * k, 0, Math.PI * 2 ); x.fill();
	const tex = new CanvasTexture( c ); tex.colorSpace = SRGBColorSpace;
	const scr = new Mesh( new PlaneGeometry( 0.31, 0.19 ),
		new MeshBasicMaterial( { map: tex } ) );
	scr.position.set( 0, 0.105, -0.106 ); scr.rotation.x = -0.28;
	g.add( scr );
	return g;
}

function makeCoffee() {
	const g = new Group();
	const cup = new Mesh(
		new CylinderGeometry( 0.05, 0.042, 0.1, 24 ),
		new MeshStandardMaterial( { color: 0xf1f5f9, roughness: 0.35 } ) );
	g.add( cup );
	const bandM = new MeshStandardMaterial( { color: 0x4f46e5, roughness: 0.5 } );
	const band = new Mesh( new CylinderGeometry( 0.051, 0.047, 0.03, 24 ), bandM );
	band.position.y = 0.005;
	g.add( band );
	// steam sprites
	const sc = document.createElement( 'canvas' ); sc.width = 32; sc.height = 64;
	const sx = sc.getContext( '2d' );
	const grad = sx.createLinearGradient( 0, 64, 0, 0 );
	grad.addColorStop( 0, 'rgba(255,255,255,.5)' );
	grad.addColorStop( 1, 'rgba(255,255,255,0)' );
	sx.strokeStyle = grad; sx.lineWidth = 5; sx.lineCap = 'round';
	sx.beginPath(); sx.moveTo( 16, 60 );
	sx.bezierCurveTo( 6, 44, 26, 30, 16, 8 ); sx.stroke();
	const stex = new CanvasTexture( sc );
	g.userData.steam = [];
	for ( let i = 0; i < 2; i++ ) {
		const s = new Mesh( new PlaneGeometry( 0.05, 0.1 ),
			new MeshBasicMaterial( { map: stex, transparent: true, depthWrite: false, opacity: 0.7 } ) );
		s.position.set( i ? 0.016 : -0.016, 0.11, 0 );
		g.add( s ); g.userData.steam.push( s );
	}
	return g;
}

/* ------------------------------------------------------------------ */
/* Instance                                                           */
/* ------------------------------------------------------------------ */
const lerp = ( a, b, t ) => a + ( b - a ) * t;
const ease = ( t ) => t * t * ( 3 - 2 * t );

class Concierge {
	constructor( el, opts = {} ) {
		this.el = el;
		this.mode = opts.mode || 'hero';
		this.reduced = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
		this.renderer = new WebGLRenderer( { antialias: true, alpha: true } );
		this.renderer.setPixelRatio( Math.min( window.devicePixelRatio || 1, 2 ) );
		this.renderer.toneMapping = ACESFilmicToneMapping;
		this.renderer.toneMappingExposure = 1.15;
		el.appendChild( this.renderer.domElement );
		this.renderer.domElement.style.cssText = 'width:100%;height:100%;display:block;';

		this.scene = new Scene();
		this.camera = new PerspectiveCamera( 34, 1, 0.1, 20 );
		if ( 'peek' === this.mode ) {
			this.camera.position.set( 0, 0.40, 1.75 );
			this.camera.lookAt( 0, 0.40, 0 );
		} else if ( 'point' === this.mode ) {
			this.camera.position.set( 0.28, 0.1, 3.3 );
			this.camera.lookAt( 0.28, -0.05, 0 );
		} else {
			this.camera.position.set( 0, 0.05, 3.45 );
			this.camera.lookAt( 0, -0.02, 0 );
		}

		this.scene.add( new AmbientLight( 0xbfc6ff, 0.55 ) );
		const key = new DirectionalLight( 0xffffff, 1.5 );
		key.position.set( 1.4, 2.2, 2.6 );
		this.scene.add( key );
		const rim = new PointLight( 0x6d79ff, 6, 8 );
		rim.position.set( -1.4, 0.6, -1.2 );
		this.scene.add( rim );
		const under = new PointLight( 0x4f46e5, 3, 4 );
		under.position.set( 0, -1.4, 0.6 );
		this.scene.add( under );

		this.holder = new Group();
		this.scene.add( this.holder );

		this.clock = new Clock();
		this.pose = { spineZ: 0, spineX: 0, headZ: 0, headX: 0, headY: 0, armLZ: 0, armLX: 0, armRZ: 0, armRX: 0, posX: 0, posY: 0, rotY: 0 };
		this.target = { ...this.pose };
		this.blend = 1;
		this.blendDur = 0.6;

		this.state = 'boot';
		this.stateT = 0;
		this.visible = true;
		this._raf = 0;

		this.resize = this.resize.bind( this );
		window.addEventListener( 'resize', this.resize );
		this.resize();

		if ( 'IntersectionObserver' in window ) {
			new IntersectionObserver( ( en ) => {
				this.visible = en[ 0 ].isIntersecting;
				if ( this.visible && ! this._raf ) this.loop();
			}, { threshold: 0.05 } ).observe( el );
		}
	}

	resize() {
		const w = this.el.clientWidth || 300, h = this.el.clientHeight || 300;
		this.renderer.setSize( w, h, false );
		this.camera.aspect = w / h;
		this.camera.updateProjectionMatrix();
	}

	attach( gltfScene, faceBase ) {
		let srcMesh = null;
		gltfScene.traverse( ( o ) => { if ( o.isMesh && ! srcMesh ) srcMesh = o; } );
		const material = srcMesh.material.clone();
		material.roughness = Math.min( 0.9, material.roughness ?? 0.6 );
		this.face = new FaceCtl( faceBase, material );
		const { mesh, bones } = buildSkinned( srcMesh.geometry.clone(), material );
		this.bones = bones;
		this.holder.add( mesh );

		this.laptop = makeLaptop(); this.laptop.visible = false;
		this.laptop.position.set( 0, -0.10, 0.42 ); this.laptop.rotation.x = 0.12;
		this.holder.add( this.laptop );
		this.coffee = makeCoffee(); this.coffee.visible = false;
		this.coffee.position.set( 0.04, -0.64, 0.16 );
		bones.armR.add( this.coffee );

		if ( 'peek' === this.mode ) {
			this.setTarget( { armLZ: 0.15, armLX: -0.8, armRZ: -0.15, armRX: -0.8 }, 0 );
		} else if ( 'point' === this.mode ) {
			this.setTarget( { armRZ: 0.55, armRX: -0.7, headZ: -0.1, headX: 0.18 }, 0 );
		}
		this.schedule( 0.5 );
		this.scheduleFace( 1.2 );
		this.loop();
	}

	setTarget( t, dur = 0.6 ) {
		this.prev = { ...this.pose };
		this.target = { ...this.pose, ...t };
		this.blend = 0;
		this.blendDur = Math.max( 0.001, dur );
	}

	/* ---- behaviour state machine (hero) ---- */
	schedule( delay ) {
		clearTimeout( this._st );
		this._st = setTimeout( () => this.nextState(), delay * 1000 );
	}

	nextState() {
		if ( this.reduced ) return;
		if ( 'hero' !== this.mode ) { this.microState(); return; }
		const pool = [ 'wave', 'flyL', 'flyR', 'tilt', 'laptop', 'coffee', 'idle', 'idle' ];
		let s = pool[ Math.floor( Math.random() * pool.length ) ];
		if ( s === this.state ) s = 'idle';
		this.state = s;
		this.stateT = 0;
		this.laptop.visible = false;
		this.coffee.visible = false;
		const D = { wave: 3.6, flyL: 6, flyR: 6, tilt: 3, laptop: 6.5, coffee: 6.5, idle: 4 };
		switch ( s ) {
			case 'wave':
				this.setTarget( { armRZ: 2.35, armRX: 0.25, headZ: -0.12, posX: this.pose.posX }, 0.55 );
				this.face.draw( 'happy' );
				break;
			case 'flyL':
				this.setTarget( { posX: -0.55, spineZ: 0.14, rotY: 0.35 }, 1.6 );
				break;
			case 'flyR':
				this.setTarget( { posX: 0.55, spineZ: -0.14, rotY: -0.35 }, 1.6 );
				break;
			case 'tilt':
				this.setTarget( { headZ: 0.22, headY: 0.3, spineZ: -0.04 }, 0.7 );
				this.face.draw( 'surprised' );
				break;
			case 'laptop':
				this.setTarget( { armLZ: -0.3, armLX: -0.95, armRZ: 0.3, armRX: -0.95, headX: 0.22, posX: 0, rotY: 0 }, 0.7 );
				setTimeout( () => { if ( 'laptop' === this.state ) this.laptop.visible = true; }, 650 );
				break;
			case 'coffee':
				this.setTarget( { armRZ: 0.35, armRX: -1.0, headZ: 0.1, posX: 0, rotY: 0 }, 0.7 );
				setTimeout( () => { if ( 'coffee' === this.state ) this.coffee.visible = true; }, 650 );
				this.face.draw( 'smile' );
				break;
			default:
				this.setTarget( { spineZ: 0, spineX: 0, headZ: 0, headX: 0, headY: 0, armLZ: 0, armLX: 0, armRZ: 0, armRX: 0, posX: 0, rotY: 0 }, 1.0 );
		}
		this.schedule( D[ s ] || 4 );
	}

	microState() {
		// point / peek: tiny variations only
		if ( 'point' === this.mode ) {
			const j = 0.12 * ( Math.random() - 0.5 );
			this.setTarget( { armRZ: 0.55 + j, armRX: -0.7 + j * 0.5, headZ: -0.08 + j, headX: 0.18 }, 1.2 );
		} else {
			const j = 0.06 * ( Math.random() - 0.5 );
			this.setTarget( { headZ: j, headY: j * 2, spineZ: j * 0.4 }, 1.5 );
		}
		this.schedule( 2.5 + Math.random() * 2.5 );
	}

	/* ---- face scheduler ---- */
	scheduleFace( delay ) {
		clearTimeout( this._ft );
		this._ft = setTimeout( () => {
			if ( this.reduced ) return;
			const r = Math.random();
			if ( r < 0.55 ) { // blink
				const back = this.face.expr;
				this.face.draw( 'blink' );
				setTimeout( () => this.face.draw( 'normal' === back || 'blink' === back ? 'normal' : back ), 140 );
			} else if ( r < 0.68 ) {
				this.face.draw( 'wink' );
				setTimeout( () => this.face.draw( 'normal' ), 900 );
			} else if ( r < 0.82 ) {
				this.face.draw( 'happy' );
				setTimeout( () => this.face.draw( 'normal' ), 1400 );
			} else if ( r < 0.92 ) {
				this.face.draw( 'smile' );
				setTimeout( () => this.face.draw( 'normal' ), 1400 );
			} else {
				this.face.draw( 'surprised' );
				setTimeout( () => this.face.draw( 'normal' ), 900 );
			}
			this.scheduleFace( 2.2 + Math.random() * 3 );
		}, delay * 1000 );
	}

	loop() {
		if ( ! this.visible ) { this._raf = 0; return; }
		this._raf = requestAnimationFrame( () => this.loop() );
		const dt = Math.min( this.clock.getDelta(), 0.05 );
		const t = this.clock.elapsedTime;
		this.stateT += dt;

		if ( this.blend < 1 ) {
			this.blend = Math.min( 1, this.blend + dt / this.blendDur );
			const k = ease( this.blend );
			for ( const key in this.target ) {
				this.pose[ key ] = lerp( this.prev[ key ], this.target[ key ], k );
			}
		}

		const p = this.pose;
		const bobAmp = 'hero' === this.mode ? 0.045 : 0.02;
		const bob = this.reduced ? 0 : Math.sin( t * 1.4 ) * bobAmp;
		const sway = this.reduced ? 0 : Math.sin( t * 0.7 ) * 0.03;

		if ( this.bones ) {
			const b = this.bones;
			b.spine.rotation.z = p.spineZ + sway * 0.4;
			b.spine.rotation.x = p.spineX;
			b.head.rotation.z = p.headZ + sway * 0.5;
			b.head.rotation.x = p.headX + ( this.reduced ? 0 : Math.sin( t * 0.9 ) * 0.02 );
			b.head.rotation.y = p.headY;
			// wave: oscillate forearm-ish
			let waveOsc = 0;
			if ( 'wave' === this.state && this.blend >= 1 ) {
				waveOsc = Math.sin( this.stateT * 9 ) * 0.28;
			}
			b.armL.rotation.z = p.armLZ - sway * 0.3;
			b.armL.rotation.x = p.armLX;
			b.armR.rotation.z = p.armRZ + waveOsc + sway * 0.3;
			b.armR.rotation.x = p.armRX;
		}
		this.holder.position.x = p.posX;
		this.holder.position.y = p.posY + bob;
		this.holder.rotation.y = p.rotY;

		if ( this.coffee.visible ) {
			this.coffee.rotation.x = -this.bones.armR.rotation.x;
			this.coffee.rotation.z = -this.bones.armR.rotation.z;
		}
		if ( this.coffee.visible && this.coffee.userData.steam ) {
			this.coffee.userData.steam.forEach( ( s, i ) => {
				s.position.y = 0.11 + ( ( t * 0.06 + i * 0.05 ) % 0.1 );
				s.material.opacity = 0.7 * ( 1 - ( ( t * 0.06 + i * 0.05 ) % 0.1 ) / 0.1 );
			} );
		}
		if ( this.laptop.visible ) {
			this.laptop.position.y = -0.10 + bob * 0.4;
		}


		this.renderer.render( this.scene, this.camera );
	}
}

/* ------------------------------------------------------------------ */
/* Bootstrap                                                          */
/* ------------------------------------------------------------------ */
const instances = [];
async function boot() {
	const els = document.querySelectorAll( '[data-concierge]' );
	if ( ! els.length ) return;
	const loader = new GLTFLoader();
	const gltf = await loader.loadAsync( MODEL_URL );
	// grab the base color image for the face canvas
	let baseImg = null;
	gltf.scene.traverse( ( o ) => {
		if ( o.isMesh && o.material && o.material.map && ! baseImg ) {
			baseImg = o.material.map.image;
		}
	} );
	els.forEach( ( el ) => {
		const inst = new Concierge( el, { mode: el.getAttribute( 'data-concierge' ) || 'hero' } );
		inst.attach( gltf.scene, baseImg );
		instances.push( inst );
	} );
	window.AgyConcierge = { instances, FMAP: ( f ) => { FMAP = f; } };
}

if ( 'loading' === document.readyState ) {
	document.addEventListener( 'DOMContentLoaded', boot );
} else {
	boot();
}
