/**
 * Kiểm tra hành vi JS trong trình duyệt thật. smoke-test.py chỉ đọc HTML nên không thấy các lỗi này.
 *
 * Site phải đang chạy. Chạy ở máy, chưa có trong CI:
 *   playwright-cli -s=hv open about:blank
 *   playwright-cli -s=hv resize 1440 900
 *   playwright-cli --raw -s=hv run-code --filename=scripts/behavior-test.js
 *   playwright-cli -s=hv close
 *
 * Trả về JSON { passed, failed, errors }. Mỗi lần chạy dùng phiên trình duyệt mới cho sạch giỏ hàng.
 */
async page => {
	const site = 'http://localhost:8080';
	const failed = [];
	const errors = [];
	let passed = 0;

	page.on( 'pageerror', ( error ) => errors.push( error.message.slice( 0, 120 ) ) );

	const check = ( name, ok, detail ) => {
		if ( ok ) {
			passed++;
		} else {
			failed.push( name + ( undefined === detail ? '' : ' → ' + JSON.stringify( detail ) ) );
		}
	};
	const open = async ( path ) => {
		await page.goto( site + path, { waitUntil: 'load' } );
		await page.waitForTimeout( 700 );
		await page.evaluate( () => {
			window.__same = 1;
		} );
	};
	const same = () => page.evaluate( () => 1 === window.__same );
	const titles = () => page.locator( '#main .woocommerce-loop-product__title' ).allInnerTexts();
	const cartCount = () => page.locator( '.kio-cart-count' ).first().innerText();

	// Trang chủ.
	await open( '/' );
	const heroIndex = () => page.evaluate( () => {
		const track = document.querySelector( '.kio-hero__track' );
		return Math.round( track.scrollLeft / track.clientWidth );
	} );
	await page.locator( '.kio-hero__track' ).hover( { position: { x: 300, y: 60 } } );
	await page.click( '.kio-hero__next' );
	await page.waitForTimeout( 800 );
	check( 'Hero: nút sau', 1 === await heroIndex() );
	await page.click( '.kio-hero__dot:nth-child(3)' );
	await page.waitForTimeout( 800 );
	check( 'Hero: chấm vị trí', 2 === await heroIndex() );

	const flash = page.locator( '.kio-flash-sale ul.products' );
	await flash.scrollIntoViewIfNeeded();
	await page.locator( '.kio-flash-sale .kio-carousel__nav--next' ).click();
	await page.waitForTimeout( 800 );
	check( 'Flash Sale: băng chuyền cuộn', ( await flash.evaluate( ( e ) => e.scrollLeft ) ) > 0 );
	check( 'Flash Sale: đồng hồ có số', /\d/.test( await page.locator( '[data-unit="seconds"]' ).innerText() ) );

	const row = '.kio-product-row[data-category="dien-thoai"]';
	await page.locator( row ).scrollIntoViewIfNeeded();
	await page.waitForTimeout( 900 );
	await page.locator( row + ' .kio-strip__chip', { hasText: 'Samsung' } ).click();
	await page.waitForTimeout( 1300 );
	const rowTitles = await page.locator( row + ' .woocommerce-loop-product__title' ).allInnerTexts();
	check( 'Chip dải sản phẩm: đổi tại chỗ', rowTitles.length > 0 && rowTitles.every( ( t ) => t.includes( 'Samsung' ) ) && await same(), rowTitles );

	await page.evaluate( async () => {
		for ( let y = 0; y < document.body.scrollHeight; y += 500 ) {
			window.scrollTo( 0, y );
			await new Promise( ( done ) => setTimeout( done, 80 ) );
		}
		await new Promise( ( done ) => setTimeout( done, 1500 ) );
	} );
	check( 'Hiệu ứng cuộn: không còn phần tử ẩn', 0 === await page.locator( '.kio-reveal' ).count() );
	await page.evaluate( () => window.scrollTo( 0, 0 ) );

	// Header.
	await page.mouse.move( 700, 500 );
	await page.mouse.wheel( 0, 900 );
	await page.waitForTimeout( 500 );
	check( 'Header: thu gọn khi cuộn xuống', await page.locator( '#masthead.is-compact' ).count() > 0 );
	await page.mouse.wheel( 0, -150 );
	await page.waitForTimeout( 500 );
	check( 'Header: hiện lại khi cuộn lên', 0 === await page.locator( '#masthead.is-compact' ).count() );
	await page.evaluate( () => window.scrollTo( 0, 0 ) );
	await page.waitForTimeout( 300 );

	const search = page.locator( '.kio-header__search input[type=search]' );
	await search.fill( '' );
	await search.pressSequentially( 'iphone', { delay: 30 } );
	await page.locator( '.kio-header__search .kio-suggest__name' ).first().waitFor( { timeout: 8000 } ).catch( () => {} );
	const hints = await page.locator( '.kio-header__search .kio-suggest__name' ).allInnerTexts();
	check( 'Gợi ý tìm kiếm: có kết quả, điện thoại đứng đầu', hints.length > 0 && hints[ 0 ].startsWith( 'iPhone' ), hints );
	await page.keyboard.press( 'Escape' );
	await search.fill( '' );

	// Trang sản phẩm.
	await open( '/san-pham/samsung-galaxy-s26/' );
	const price = () => page.locator( '.summary > .price' ).innerText();
	const before = await price();
	await page.locator( '.kio-chip', { hasText: '512GB' } ).click();
	await page.waitForTimeout( 500 );
	check( 'Chip phiên bản: đổi giá', before !== await price() );
	await page.locator( '.kio-chip', { hasText: 'Xanh Sky Blue' } ).click();
	await page.waitForTimeout( 600 );
	const thumb = await page.evaluate( () => document.querySelector( '.flex-control-thumbs img.flex-active' )?.src || '' );
	check( 'Chip phiên bản: ảnh theo màu', thumb.includes( 'xanh-sky-blue' ), thumb );
	await page.click( '.single_add_to_cart_button' );
	await page.waitForTimeout( 2000 );
	check( 'Nút mua: không tải lại, số giỏ tăng', await same() && '1' === await cartCount() );
	check( 'Nút mua: có thông báo', ( await page.locator( '.kio-toast__text' ).innerText() ).includes( '512GB' ) );

	// Yêu thích, so sánh trên thẻ.
	await open( '/danh-muc-san-pham/phu-kien/' );
	await page.locator( '#main .woosw-btn' ).first().click();
	await page.waitForTimeout( 1600 );
	check( 'Yêu thích: thả tim', await page.locator( '#main .woosw-btn.woosw-added' ).count() > 0 );
	await page.keyboard.press( 'Escape' );
	await page.evaluate( () => document.querySelector( '.woosw-popup-close' )?.click() );
	await page.waitForTimeout( 300 );
	await page.locator( '#main a.compare.button' ).first().click( { force: true } );
	await page.waitForTimeout( 2200 );
	check( 'So sánh: thêm được', await page.locator( '#main a.compare.added' ).count() > 0 || await page.locator( '#yith-woocompare, .yith-woocompare-popup, #cboxContent' ).count() > 0 );
	await page.keyboard.press( 'Escape' );
	await page.evaluate( () => document.querySelector( '#cboxClose, .yith-woocompare-popup-close' )?.click() );
	await page.waitForTimeout( 300 );

	await open( '/danh-muc-san-pham/phu-kien/' );
	const count = Number( await cartCount() );
	await page.locator( '#main li.product a.ajax_add_to_cart' ).first().click();
	await page.waitForTimeout( 2000 );
	check( 'Thêm từ thẻ: số giỏ tăng', Number( await cartCount() ) === count + 1 );

	// Danh mục.
	await open( '/danh-muc-san-pham/dien-thoai/' );
	await page.locator( '.kio-filter-chip', { hasText: 'Samsung' } ).click();
	await page.waitForURL( ( url ) => url.search.includes( 'filter_product_brand' ) );
	await page.waitForTimeout( 500 );
	check( 'Lọc: tại chỗ', await same() && ( await titles() ).every( ( t ) => t.includes( 'Samsung' ) ) );
	check( 'Lọc: thanh kéo giá còn sau khi thay sidebar', 1 === await page.locator( '.kio-price-range' ).count() );
	await page.goBack();
	await page.waitForFunction( () => 10 === document.querySelectorAll( 'ul.products li.product' ).length, null, { timeout: 8000 } ).catch( () => {} );
	check( 'Lọc: nút Back', 10 === ( await titles() ).length, ( await titles() ).length );

	await open( '/cua-hang/' );
	await page.locator( '.kio-load-more__button' ).click();
	// Chờ theo điều kiện: ngay sau khi xoá cache trang tải chậm hơn, chờ cứng 1,5 giây thì lúc đạt lúc không.
	await page.waitForFunction( () => document.querySelectorAll( 'ul.products li.product' ).length >= 20, null, { timeout: 8000 } ).catch( () => {} );
	check( 'Xem thêm: nối thêm sản phẩm', 20 === ( await titles() ).length && await same(), ( await titles() ).length );

	// Thanh kéo giá có mốc. Điện thoại có các mốc 5, 7, 10, 20 triệu và không giới hạn.
	await open( '/danh-muc-san-pham/dien-thoai/' );
	await page.locator( '.kio-price-range__input--min' ).fill( '2' );
	await page.waitForFunction( () => location.search.includes( 'min_price=10000000' ), null, { timeout: 8000 } ).catch( () => {} );
	await page.waitForTimeout( 800 );
	check( 'Giá: kéo đầu trái lọc đúng mốc, giữ vị trí sau khi thay sidebar', '2' === await page.locator( '.kio-price-range__input--min' ).inputValue(), page.url() );
	await page.locator( '.kio-price-range__input--max' ).fill( '3' );
	await page.waitForFunction( () => location.search.includes( 'max_price=20000000' ), null, { timeout: 8000 } ).catch( () => {} );
	await page.waitForTimeout( 800 );
	check( 'Giá: kéo đầu phải, chip đang chọn khớp mốc', 1 === await page.locator( '.kio-filter-chip.is-active', { hasText: '10 - 20 triệu' } ).count(), page.url() );
	await page.locator( '.kio-price-range__input--min' ).evaluate( ( e ) => {
		e.value = '3';
		e.dispatchEvent( new Event( 'input' ) );
	} );
	check( 'Giá: 2 đầu không vượt qua nhau', '2' === await page.locator( '.kio-price-range__input--min' ).inputValue() );

	// Mô hình 3D: tự chuyển sang máy đang xoay, kéo được, đổi màu, dải ảnh nhỏ vẫn bấm được.
	await open( '/san-pham/samsung-galaxy-s26/' );
	await page.waitForFunction( () => document.querySelector( '.kio-model-stage.is-ready' ), null, { timeout: 30000 } ).catch( () => {} );
	const spin = () => page.evaluate( () => document.querySelector( 'model-viewer' ).turntableRotation );
	const spinBefore = await spin();
	await page.waitForTimeout( 600 );
	check( '3D: tự xoay khi vào trang', spinBefore !== await spin() );
	await open( '/san-pham/iphone-17-pro-max/' );
	await page.evaluate( () => window.scrollTo( 0, 1600 ) );
	await page.waitForTimeout( 1800 );
	check( '3D: khung ảnh ngoài màn hình thì chưa tải', 0 === await page.locator( 'model-viewer' ).count() );
	await open( '/san-pham/samsung-galaxy-s26/' );
	await page.waitForFunction( () => document.querySelector( '.kio-model-stage.is-ready' ), null, { timeout: 30000 } ).catch( () => {} );
	const orbit = () => page.evaluate( () => document.querySelector( 'model-viewer' ).getCameraOrbit().theta );
	const viewerBox = await page.locator( 'model-viewer' ).boundingBox();
	const orbitBefore = await orbit();
	await page.mouse.move( viewerBox.x + viewerBox.width / 2, viewerBox.y + viewerBox.height / 2 );
	await page.mouse.down();
	await page.mouse.move( viewerBox.x + viewerBox.width / 2 + 150, viewerBox.y + viewerBox.height / 2, { steps: 8 } );
	await page.mouse.up();
	await page.waitForTimeout( 900 );
	check( '3D: kéo chuột xoay được, thôi tự xoay', 0.2 < Math.abs( await orbit() - orbitBefore ) && ! await page.evaluate( () => document.querySelector( 'model-viewer' ).hasAttribute( 'auto-rotate' ) ) );
	await page.locator( '.kio-chip', { hasText: 'Xanh Sky Blue' } ).first().click();
	await page.waitForTimeout( 500 );
	check( '3D: đổi màu theo biến thể', await page.evaluate( () => {
		const c = document.querySelector( 'model-viewer' ).model.getMaterialByName( 'back' ).pbrMetallicRoughness.baseColorFactor;
		return c[ 2 ] > c[ 0 ];
	} ) );
	check( '3D: nút đóng không bị phần tử khác đè', await page.locator( '.kio-model-stage__close' ).evaluate( ( e ) => {
		const box = e.getBoundingClientRect();
		return [ box.left + 6, box.right - 6 ].every( ( x ) => document.elementFromPoint( x, box.top + box.height / 2 ) === e );
	} ) );
	await page.locator( '.flex-control-thumbs img' ).nth( 1 ).click();
	await page.waitForTimeout( 400 );
	check( '3D: bấm ảnh nhỏ thì về ảnh, còn nút xem lại', ! await page.locator( '.kio-model-stage' ).isVisible() && await page.locator( '.kio-model-open' ).isVisible() );
	await page.locator( '.kio-model-open' ).click();
	await page.waitForTimeout( 500 );
	check( '3D: mở lại được', await page.locator( '.kio-model-stage' ).isVisible() );

	// Thanh mua nổi trên desktop, câu hỏi thường gặp.
	await open( '/san-pham/samsung-galaxy-s26/' );
	check( 'Trang sản phẩm: nút mua trong màn hình đầu', await page.locator( '.single_add_to_cart_button' ).evaluate( ( e ) => e.getBoundingClientRect().bottom < innerHeight ) );
	await page.evaluate( () => window.scrollTo( 0, 1400 ) );
	await page.waitForTimeout( 500 );
	check( 'Thanh mua: hiện trên desktop khi cuộn qua nút', await page.locator( '.kio-buy-bar.is-visible' ).evaluate( ( e ) => {
		const box = e.getBoundingClientRect();
		return box.bottom <= innerHeight && box.width < innerWidth;
	} ) );
	await page.locator( '.kio-faq summary' ).first().click();
	check( 'Câu hỏi: mở được', await page.locator( '.kio-faq details' ).first().evaluate( ( e ) => e.open ) );

	// Bảng thông số.
	await open( '/san-pham/samsung-galaxy-s26/' );
	const specMore = page.locator( '.kio-spec-table__more' );
	await specMore.click();
	check( 'Thông số: mở tại chỗ', await page.locator( '.kio-spec-table__full' ).isVisible() && ! await page.locator( '.kio-spec-table__summary' ).isVisible() );
	check( 'Thông số: báo trạng thái mở', 'true' === await specMore.getAttribute( 'aria-expanded' ) );
	await specMore.click();
	await page.waitForTimeout( 300 );
	check( 'Thông số: thu gọn về bảng rút gọn', await page.locator( '.kio-spec-table__summary' ).isVisible() && ! await page.locator( '.kio-spec-table__full' ).isVisible() );
	check( 'Thông số: thu gọn quay về đầu mục', await page.locator( '.kio-spec-table' ).evaluate( ( e ) => {
		// Không bị header dính che (cao khoảng 80px) và vẫn ở nửa trên màn hình.
		const top = e.getBoundingClientRect().top;
		return top >= 80 && top < innerHeight / 2;
	} ) );

	// Giỏ hàng.
	await open( '/gio-hang/' );
	const total = () => page.locator( '.order-total td' ).innerText();
	const totalBefore = await total();
	await page.evaluate( () => window.scrollTo( 0, 100 ) );
	await page.locator( '.kio-qty__btn[data-step="1"]' ).first().click();
	await page.waitForTimeout( 2300 );
	check( 'Giỏ: tự cập nhật, không nhảy trang', totalBefore !== await total() && 100 === await page.evaluate( () => Math.round( window.scrollY ) ) && await same() );

	// Thanh toán.
	await open( '/thanh-toan/' );
	await page.waitForTimeout( 900 );
	await page.click( '#place_order' );
	await page.waitForTimeout( 2800 );
	const layout = await page.evaluate( () => {
		const box = ( selector ) => document.querySelector( selector ).getBoundingClientRect();
		return { left: Math.round( box( '#customer_details' ).left ), side: Math.round( box( '.kio-order-side' ).left ), top: Math.round( box( '#customer_details' ).top - box( '.kio-order-side' ).top ) };
	} );
	check( 'Thanh toán: báo lỗi không vỡ 2 cột', layout.left < layout.side && 0 === layout.top, layout );
	check( 'Thanh toán: hộp lỗi một dòng', /Vui lòng kiểm tra \d+ mục/.test( await page.locator( '.woocommerce-error' ).innerText() ) );
	await page.locator( '.woocommerce-error a' ).first().click();
	await page.waitForTimeout( 400 );
	check( 'Thanh toán: bấm lỗi tới đúng ô', 'billing_first_name' === await page.evaluate( () => document.activeElement.id ) );

	// Page cache: 2 khách riêng biệt. Khách A đã có giỏ, tim, so sánh ở trên.
	const other = await page.context().browser().newContext();
	const guest = await other.newPage();
	const list = '/danh-muc-san-pham/phu-kien/';
	await guest.goto( site + list, { waitUntil: 'load' } );
	const cachedPage = await guest.goto( site + list, { waitUntil: 'load' } );
	await guest.waitForTimeout( 800 );
	check( 'Khách B: trang đến từ cache', 'cache-enabler-engine' === cachedPage.headers()[ 'x-cache-handler' ] );
	check( 'Khách B: không thấy tim của A', 0 === await guest.locator( '#main .woosw-btn.woosw-added' ).count() );
	check( 'Khách B: giỏ trống', '0' === await guest.locator( '.kio-cart-count' ).first().innerText() );
	await guest.locator( '#main .woosw-btn' ).first().click();
	await guest.waitForTimeout( 1600 );
	await guest.goto( site + list, { waitUntil: 'load' } );
	await guest.waitForTimeout( 800 );
	check( 'Khách B: tải lại vẫn thấy tim của mình', await guest.locator( '#main .woosw-btn.woosw-added' ).count() > 0 );
	await guest.locator( '#main li.product a.ajax_add_to_cart' ).first().click();
	await guest.waitForTimeout( 1800 );
	check( 'Khách B: trên trang cache vẫn thêm vào giỏ được', '1' === await guest.locator( '.kio-cart-count' ).first().innerText() );
	await other.close();

	await page.goto( site + list, { waitUntil: 'load' } );
	await page.waitForTimeout( 800 );
	check( 'Khách A: tải lại thấy tim của mình', await page.locator( '#main .woosw-btn.woosw-added' ).count() > 0 );

	check( 'Không có lỗi JS', 0 === errors.length, errors.slice( 0, 3 ) );

	return JSON.stringify( { passed, failed, errors } );
}
