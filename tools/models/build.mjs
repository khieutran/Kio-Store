/**
 * Dựng mô hình 3D minh hoạ từ kích thước sản phẩm, xuất GLB vào plugin kio-store.
 *
 * Tự dựng bằng hình khối nên không dùng ảnh hay mô hình có bản quyền, không có logo hãng. Đây là mô hình
 * minh hoạ, không phải bản sao chính xác. Vật liệu "back", "frame" được đặt tên để JS đổi màu theo biến thể.
 *
 * Chạy: cd tools/models && npm ci && npm run build
 */
import { copyFileSync, mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import * as THREE from 'three';
import { GLTFExporter } from 'three/addons/exporters/GLTFExporter.js';
import { mergeGeometries } from 'three/addons/utils/BufferGeometryUtils.js';

// GLTFExporter chạy trong trình duyệt, Node thiếu FileReader.
globalThis.FileReader = class {
	readAsArrayBuffer( blob ) {
		blob.arrayBuffer().then( ( buffer ) => {
			this.result = buffer;
			this.onloadend?.();
		} );
	}
};

const here = dirname( fileURLToPath( import.meta.url ) );
const plugin = join( here, '../../wp-content/plugins/kio-store/assets' );
const MM = 0.001; // glTF tính bằng mét: model-viewer hiện đúng tỉ lệ thật.

function roundedRect( width, height, radius ) {
	const x = -width / 2;
	const y = -height / 2;
	const shape = new THREE.Shape();
	shape.moveTo( x + radius, y );
	shape.lineTo( x + width - radius, y );
	shape.quadraticCurveTo( x + width, y, x + width, y + radius );
	shape.lineTo( x + width, y + height - radius );
	shape.quadraticCurveTo( x + width, y + height, x + width - radius, y + height );
	shape.lineTo( x + radius, y + height );
	shape.quadraticCurveTo( x, y + height, x, y + height - radius );
	shape.lineTo( x, y + radius );
	shape.quadraticCurveTo( x, y, x + radius, y );
	return shape;
}

function slab( width, height, radius, depth, bevel, material, name ) {
	const geometry = new THREE.ExtrudeGeometry( roundedRect( width - bevel * 2, height - bevel * 2, Math.max( radius - bevel, 0.1 ) ), {
		depth: depth - bevel * 2,
		bevelEnabled: bevel > 0,
		bevelThickness: bevel,
		bevelSize: bevel,
		bevelSegments: 3,
		curveSegments: 10,
	} );
	geometry.translate( 0, 0, -( depth - bevel * 2 ) / 2 );
	const mesh = new THREE.Mesh( geometry, material );
	mesh.name = name;
	return mesh;
}

function disc( radius, depth, material, name ) {
	const geometry = new THREE.CylinderGeometry( radius, radius, depth, 32 );
	geometry.rotateX( Math.PI / 2 );
	const mesh = new THREE.Mesh( geometry, material );
	mesh.name = name;
	return mesh;
}

/** Màn hình: mảng màu chuyển dần lưu ở đỉnh, không cần file ảnh. */
function screen( width, height, radius, top, bottom ) {
	const geometry = new THREE.ShapeGeometry( roundedRect( width, height, radius ), 12 );
	const position = geometry.attributes.position;
	const colors = [];
	const from = new THREE.Color( bottom );
	const to = new THREE.Color( top );
	for ( let i = 0; i < position.count; i++ ) {
		const c = from.clone().lerp( to, ( position.getY( i ) + height / 2 ) / height );
		colors.push( c.r, c.g, c.b );
	}
	geometry.setAttribute( 'color', new THREE.Float32BufferAttribute( colors, 3 ) );
	const material = new THREE.MeshBasicMaterial( { vertexColors: true } );
	material.name = 'screen';
	const mesh = new THREE.Mesh( geometry, material );
	mesh.name = 'screen';
	return mesh;
}

function materials( color ) {
	const pbr = ( name, base, metalness, roughness ) => new THREE.MeshStandardMaterial( { name, color: base, metalness, roughness } );
	return {
		frame: pbr( 'frame', color, 0.85, 0.32 ),
		back: pbr( 'back', color, 0.25, 0.38 ),
		glass: pbr( 'glass', '#050507', 0.2, 0.08 ),
		lens: pbr( 'lens', '#0a0c14', 0.6, 0.05 ),
		dark: pbr( 'dark', '#101013', 0.3, 0.6 ),
		flash: pbr( 'flash', '#f5efd9', 0, 0.4 ),
		ring: pbr( 'ring', '#8d9096', 0.95, 0.28 ),
	};
}

/** Ống kính: vòng kim loại nhô lên, mắt kính ở giữa. Vị trí theo mặt lưng, z là mặt lưng. */
function addLenses( group, m, lenses, z ) {
	lenses.forEach( ( [ x, y, size ], index ) => {
		const bezel = disc( size, 1.5, m.ring, `camera-ring-${ index }` );
		bezel.position.set( x, y, z - 0.75 );
		group.add( bezel );
		const eye = disc( size - 1.1, 0.3, m.lens, `camera-lens-${ index }` );
		eye.position.set( x, y, z - 1.45 );
		group.add( eye );
	} );
}

function phone( { width, height, depth, radius, color, lenses, island, top = '#3b6fd8', bottom = '#0b1a44' } ) {
	const group = new THREE.Group();

	const { frame, back, glass, lens, dark, flash, ring } = materials( color );

	group.add( slab( width, height, radius, depth, 1.1, frame, 'body' ) );

	// Mặt trước: kính đen, màn hình lùi vào tạo viền, lỗ camera trước.
	const front = slab( width - 0.7, height - 0.7, radius - 0.35, 0.5, 0.15, glass, 'front' );
	front.position.z = depth / 2 - 0.1;
	group.add( front );
	const display = screen( width - 3.2, height - 3.2, radius - 1.6, top, bottom );
	// Cách kính 0.1mm: sát hơn thì card đồ hoạ không phân biệt được lớp nào trước, mép màn hình bị răng cưa.
	display.position.z = depth / 2 + 0.25;
	group.add( display );
	const hole = disc( 1.7, 0.1, glass, 'front-camera' );
	hole.position.set( 0, height / 2 - 7, depth / 2 + 0.32 );
	group.add( hole );

	// Mặt lưng.
	const plate = slab( width - 0.7, height - 0.7, radius - 0.35, 0.5, 0.15, back, 'back-plate' );
	plate.position.z = -depth / 2 + 0.1;
	group.add( plate );

	// Bệ camera nổi trên mặt lưng, nếu máy có.
	if ( island ) {
		const base = slab( island.width, island.height, island.radius, 1.6, 0.5, frame, 'camera-island' );
		base.position.set( island.x, island.y, -depth / 2 - 0.4 );
		group.add( base );
	}
	addLenses( group, { ring, lens }, lenses, island ? -depth / 2 - 0.9 : -depth / 2 );
	const led = disc( 1.5, 0.3, flash, 'flash' );
	led.position.set( lenses[ 0 ][ 0 ] + 11, lenses[ 0 ][ 1 ], island ? -depth / 2 - 1.3 : -depth / 2 - 0.1 );
	group.add( led );

	// Nút bên phải, cổng sạc và lỗ loa dưới đáy.
	[ [ height / 2 - 40, 20 ], [ height / 2 - 62, 11 ] ].forEach( ( [ y, length ], index ) => {
		const key = slab( 1.2, length, 0.55, 2.4, 0.2, frame, `button-${ index }` );
		key.position.set( width / 2 + 0.25, y, 0 );
		group.add( key );
	} );
	const port = slab( 9, 3.2, 1.5, 1, 0, dark, 'usb-c' );
	port.rotation.x = Math.PI / 2;
	port.position.set( 0, -height / 2 + 0.2, 0 );
	group.add( port );
	[ -18, -15.5, -13, 13, 15.5, 18 ].forEach( ( x, index ) => {
		const holeSpeaker = disc( 0.6, 1, dark, `speaker-${ index }` );
		holeSpeaker.rotation.x = Math.PI / 2;
		holeSpeaker.position.set( x, -height / 2 + 0.2, 0 );
		group.add( holeSpeaker );
	} );

	group.scale.setScalar( MM );
	return group;
}

/** Điện thoại gập vỏ sò, đang mở: màn hình ngoài nửa trên mặt lưng, dải bản lề ngang. */
function flip( { width, height, depth, color } ) {
	const group = phone( { width, height, depth, radius: 9, color, lenses: [ [ -24, 68, 5 ], [ -24, 55, 5 ] ], top: '#4c7be0', bottom: '#101c4a' } );
	const m = materials( color );
	group.scale.setScalar( 1 );

	// Bản lề chỉ lộ ở 2 cạnh và mặt lưng, không đè lên màn hình.
	const hinge = slab( width + 0.4, 5, 0.6, depth - 0.8, 0.2, m.dark, 'hinge' );
	hinge.position.z = -0.9;
	group.add( hinge );

	const cover = slab( width - 5, height / 2 - 8, 8, 0.6, 0.15, m.glass, 'cover-glass' );
	cover.position.set( 0, height / 4 + 1, -depth / 2 - 0.35 );
	group.add( cover );
	const outer = screen( width - 8, height / 2 - 11, 7, '#3b6fd8', '#0b1a44' );
	outer.rotation.y = Math.PI;
	outer.position.set( 0, height / 4 + 1, -depth / 2 - 0.75 );
	group.add( outer );

	addLenses( group, m, [ [ -24, 68, 5 ], [ -24, 55, 5 ] ], -depth / 2 - 0.65 );

	const crease = new THREE.Mesh( new THREE.PlaneGeometry( width - 3.5, 0.25 ), m.dark );
	crease.name = 'crease';
	crease.position.z = depth / 2 + 0.3;
	group.add( crease );

	group.scale.setScalar( MM );
	return group;
}

function tablet( { width, height, depth, radius, color, lenses } ) {
	const group = new THREE.Group();
	const m = materials( color );

	group.add( slab( width, height, radius, depth, 0.8, m.frame, 'body' ) );

	const front = slab( width - 0.7, height - 0.7, radius - 0.3, 0.5, 0.15, m.glass, 'front' );
	front.position.z = depth / 2 - 0.1;
	group.add( front );
	const display = screen( width - 13, height - 13, radius - 5, '#6a8fe8', '#1a2452' );
	display.position.z = depth / 2 + 0.25;
	group.add( display );

	const plate = slab( width - 0.7, height - 0.7, radius - 0.3, 0.5, 0.15, m.back, 'back-plate' );
	plate.position.z = -depth / 2 + 0.1;
	group.add( plate );
	addLenses( group, m, lenses, -depth / 2 );

	group.scale.setScalar( MM );
	return group;
}

function laptop( { width, depth, thickness, color } ) {
	const group = new THREE.Group();
	const m = materials( color );
	const lidThick = thickness * 0.42;

	// Thân máy nằm ngang: dựng theo mặt phẳng XY rồi xoay nằm xuống.
	const base = new THREE.Group();
	base.add( slab( width, depth, 12, thickness * 0.58, 0.9, m.frame, 'base' ) );

	const deck = slab( width - 22, depth * 0.47, 3, 0.4, 0.1, m.dark, 'keyboard' );
	deck.position.set( 0, depth * 0.2, thickness * 0.29 + 0.05 );
	base.add( deck );
	const keys = [];
	const cols = 14;
	const rows = 5;
	const keyW = ( width - 28 ) / cols - 1.3;
	const keyH = ( depth * 0.47 - 6 ) / rows - 1.3;
	for ( let r = 0; r < rows; r++ ) {
		for ( let c = 0; c < cols; c++ ) {
			const g = new THREE.BoxGeometry( keyW, keyH, 0.3 );
			g.translate( -( width - 28 ) / 2 + ( c + 0.5 ) * ( ( width - 28 ) / cols ), depth * 0.2 - ( depth * 0.47 - 6 ) / 2 + ( r + 0.5 ) * ( ( depth * 0.47 - 6 ) / rows ), thickness * 0.29 + 0.4 );
			keys.push( g );
		}
	}
	const keyboard = new THREE.Mesh( mergeGeometries( keys ), m.lens );
	keyboard.name = 'keys';
	base.add( keyboard );

	const pad = slab( width * 0.38, depth * 0.3, 3, 0.3, 0.1, m.back, 'trackpad' );
	pad.position.set( 0, -depth * 0.28, thickness * 0.29 + 0.05 );
	base.add( pad );

	base.rotation.x = -Math.PI / 2;
	group.add( base );

	// Nắp mở nghiêng, bản lề ở cạnh sau.
	const lid = new THREE.Group();
	const shell = slab( width, depth, 12, lidThick, 0.6, m.back, 'lid' );
	shell.position.y = depth / 2;
	lid.add( shell );
	const bezel = slab( width - 1, depth - 1, 11.5, 0.3, 0.1, m.glass, 'bezel' );
	bezel.position.set( 0, depth / 2, lidThick / 2 + 0.05 );
	lid.add( bezel );
	const display = screen( width - 12, depth - 14, 3, '#5f86e6', '#141f4d' );
	display.position.set( 0, depth / 2 + 1, lidThick / 2 + 0.3 );
	lid.add( display );
	lid.position.set( 0, thickness * 0.3, -depth / 2 );
	lid.rotation.x = -0.32;
	group.add( lid );

	group.scale.setScalar( MM );
	return group;
}

function watch( { width, height, depth, color } ) {
	const group = new THREE.Group();
	const m = materials( color );
	const band = new THREE.MeshStandardMaterial( { name: 'band', color: '#2a2c31', metalness: 0, roughness: 0.75 } );

	group.add( slab( width, height, 10, depth, 1.6, m.frame, 'case' ) );

	const front = slab( width - 1.2, height - 1.2, 9.4, 0.6, 0.25, m.glass, 'front' );
	front.position.z = depth / 2 - 0.1;
	group.add( front );
	const display = screen( width - 6, height - 6, 7, '#ff8a4c', '#3a1250' );
	display.position.z = depth / 2 + 0.3;
	group.add( display );

	const sensor = disc( 11, 1, m.glass, 'sensor' );
	sensor.position.z = -depth / 2 - 0.3;
	group.add( sensor );

	const crown = disc( 3.2, 2.6, m.ring, 'crown' );
	crown.rotation.y = Math.PI / 2;
	crown.rotation.x = 0;
	crown.position.set( width / 2 + 0.9, 6, 0 );
	group.add( crown );
	const side = slab( 1.4, 10, 0.6, 2.8, 0.2, m.frame, 'side-button' );
	side.position.set( width / 2 + 0.2, -7, 0 );
	group.add( side );

	[ 1, -1 ].forEach( ( direction, index ) => {
		const strap = slab( width - 11, 34, 4, 2.6, 0.7, band, `band-${ index }` );
		strap.position.set( 0, direction * ( height / 2 + 15 ), -1.2 );
		strap.rotation.x = direction * 0.2;
		group.add( strap );
	} );

	group.scale.setScalar( MM );
	return group;
}

function exportGlb( object, file ) {
	return new Promise( ( resolve, reject ) => {
		new GLTFExporter().parse( object, ( data ) => {
			writeFileSync( file, Buffer.from( data ) );
			resolve( data.byteLength );
		}, reject, { binary: true } );
	} );
}

// Kích thước lấy từ data/specs.json. Màu là màu mặc định, JS đổi theo biến thể.
const products = {
	'SS-S26': phone( {
		width: 71.7, height: 149.6, depth: 7.2, radius: 11, color: '#1d1e20',
		lenses: [ [ -22, 61, 5.2 ], [ -22, 47, 5.2 ], [ -22, 33, 5.2 ] ],
	} ),
	'APL-IP17PM': phone( {
		width: 78, height: 163.4, depth: 8.75, radius: 13, color: '#d8d8d8', top: '#ff9d5c', bottom: '#5a1f4e',
		island: { width: 72, height: 42, radius: 9, x: 0, y: 56 },
		lenses: [ [ -22, 66, 7.5 ], [ -22, 47, 7.5 ], [ -4, 56.5, 7.5 ] ],
	} ),
	'SS-ZFLIP7': flip( { width: 75.2, height: 166.7, depth: 6.5, color: '#17181a' } ),
	'APL-IPADPRO13M5': tablet( { width: 215.5, height: 281.6, depth: 5.1, radius: 14, color: '#d8d8d8', lenses: [ [ -92, 124, 7 ] ] } ),
	'SS-TABS11': tablet( { width: 165.3, height: 253.8, depth: 5.5, radius: 12, color: '#d8d8d8', lenses: [ [ -68, 112, 5 ] ] } ),
	'APL-MBA13M4': laptop( { width: 304.1, depth: 215, thickness: 11.3, color: '#d8d8d8' } ),
	'AS-VIVO15OLED': laptop( { width: 353.6, depth: 246.9, thickness: 15, color: '#2b2c2f' } ),
	'APL-AW11': watch( { width: 36, height: 42, depth: 9.7, color: '#d8d8d8' } ),
};

mkdirSync( join( plugin, 'models' ), { recursive: true } );
mkdirSync( join( plugin, 'vendor' ), { recursive: true } );
for ( const [ sku, model ] of Object.entries( products ) ) {
	const bytes = await exportGlb( model, join( plugin, 'models', `${ sku }.glb` ) );
	console.log( `${ sku }.glb ${ ( bytes / 1024 ).toFixed( 0 ) } KB` );
}
copyFileSync( join( here, 'node_modules/@google/model-viewer/dist/model-viewer.min.js' ), join( plugin, 'vendor', 'model-viewer.min.js' ) );
