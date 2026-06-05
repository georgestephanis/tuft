( function () {
	'use strict';

	if ( typeof tuftSettings === 'undefined' ) {
		return;
	}

	const settings = tuftSettings;

	// ── CSS selector generator ──────────────────────────────────

	function getSelector( target ) {
		if ( ! target || target === document.body ) {
			return 'body';
		}
		if ( target.id ) {
			return '#' + target.id;
		}

		const parts = [];
		let current = target;

		while (
			current &&
			current !== document.body &&
			current.nodeType === Node.ELEMENT_NODE
		) {
			if ( current.id ) {
				parts.unshift( '#' + current.id );
				break;
			}

			let part = current.tagName.toLowerCase();

			const classes = Array.from( current.classList )
				.filter( function ( c ) {
					return ! c.startsWith( 'tuft-' );
				} )
				.slice( 0, 2 );
			if ( classes.length ) {
				part += '.' + classes.join( '.' );
			}

			const siblings = current.parentElement
				? Array.from( current.parentElement.children ).filter(
						function ( s ) {
							return s.tagName === current.tagName;
						}
				  )
				: [];
			if ( siblings.length > 1 ) {
				part +=
					':nth-of-type(' + ( siblings.indexOf( current ) + 1 ) + ')';
			}

			parts.unshift( part );
			current = current.parentElement;
			if ( parts.length >= 5 ) {
				break;
			}
		}

		return parts.join( ' > ' );
	}

	// ── Form state capture ──────────────────────────────────────

	function getFormState() {
		const forms = document.querySelectorAll( 'form' );
		if ( ! forms.length ) {
			return null;
		}

		const state = [];
		forms.forEach( function ( form, i ) {
			const fields = {};
			form.querySelectorAll( 'input, select, textarea' ).forEach(
				function ( field ) {
					if ( ! field.name ) {
						return;
					}
					if (
						field.type === 'password' ||
						field.type === 'hidden' ||
						field.type === 'submit' ||
						field.type === 'button'
					) {
						return;
					}
					if ( field.type === 'checkbox' || field.type === 'radio' ) {
						fields[ field.name ] = field.checked;
					} else {
						fields[ field.name ] = field.value;
					}
				}
			);
			if ( Object.keys( fields ).length ) {
				state.push( { index: i, id: form.id || null, fields } );
			}
		} );

		return state.length ? state : null;
	}

	// ── WordPress Playground CORS/Mixed Content Workaround ──────

	function fixPlaygroundAssets() {
		const scopeMatch =
			window.location.pathname.match( /^\/scope\/([^/]+)/ );
		if ( ! scopeMatch ) {
			return;
		}

		const scope = scopeMatch[ 0 ];
		const sameOriginBase = window.location.origin + scope;

		// Rewrite stylesheet links to be same-origin
		document
			.querySelectorAll( 'link[rel="stylesheet"]' )
			.forEach( function ( link ) {
				const href = link.getAttribute( 'href' );
				if ( ! href ) {
					return;
				}

				if (
					href.indexOf( window.location.origin ) === -1 &&
					( href.indexOf( '/wp-content/' ) !== -1 ||
						href.indexOf( '/wp-includes/' ) !== -1 )
				) {
					let relPath = '';
					const wpContentIdx = href.indexOf( '/wp-content/' );
					const wpIncludesIdx = href.indexOf( '/wp-includes/' );

					if ( wpContentIdx !== -1 ) {
						relPath = href.substring( wpContentIdx );
					} else if ( wpIncludesIdx !== -1 ) {
						relPath = href.substring( wpIncludesIdx );
					}

					if ( relPath ) {
						link.setAttribute( 'href', sameOriginBase + relPath );
					}
				}
			} );

		// Rewrite images to be same-origin to prevent tainted canvas / mixed content blocks
		document.querySelectorAll( 'img' ).forEach( function ( img ) {
			const src = img.getAttribute( 'src' );
			if ( ! src ) {
				return;
			}

			if (
				src.indexOf( window.location.origin ) === -1 &&
				( src.indexOf( '/wp-content/' ) !== -1 ||
					src.indexOf( '/wp-includes/' ) !== -1 )
			) {
				let relPath = '';
				const wpContentIdx = src.indexOf( '/wp-content/' );
				const wpIncludesIdx = src.indexOf( '/wp-includes/' );

				if ( wpContentIdx !== -1 ) {
					relPath = src.substring( wpContentIdx );
				} else if ( wpIncludesIdx !== -1 ) {
					relPath = src.substring( wpIncludesIdx );
				}

				if ( relPath ) {
					img.setAttribute( 'src', sameOriginBase + relPath );
				}
			}
		} );
	}

	// ── Screenshot ─────────────────────────────────────────────

	function takeScreenshot() {
		if ( typeof html2canvas === 'undefined' ) {
			return Promise.resolve( null );
		}

		return html2canvas( document.documentElement, {
			x: window.scrollX,
			y: window.scrollY,
			width: window.innerWidth,
			height: window.innerHeight,
			windowWidth: document.documentElement.scrollWidth,
			windowHeight: document.documentElement.scrollHeight,
			useCORS: true,
			allowTaint: true,
			logging: false,
			scale: Math.min( window.devicePixelRatio || 1, 2 ),
			ignoreElements( el ) {
				// Skip our own UI elements so they don't appear in the screenshot.
				return !! el.id && el.id.startsWith( 'tuft-' );
			},
		} )
			.then( function ( canvas ) {
				const MAX_W = 1280;
				if ( canvas.width <= MAX_W ) {
					return canvas.toDataURL( 'image/jpeg', 0.75 );
				}
				const scaled = document.createElement( 'canvas' );
				const ratio = MAX_W / canvas.width;
				scaled.width = MAX_W;
				scaled.height = Math.round( canvas.height * ratio );
				scaled
					.getContext( '2d' )
					.drawImage( canvas, 0, 0, scaled.width, scaled.height );
				return scaled.toDataURL( 'image/jpeg', 0.75 );
			} )
			.catch( function () {
				return null;
			} );
	}

	// ── Core widget ────────────────────────────────────────────

	const DF = {
		targeting: false,
		captured: null,
		button: null,
		overlay: null,
		highlight: null,
		backdrop: null,
		modal: null,

		// Bound event handlers (stored for removeEventListener)
		_onClick: null,
		_onHover: null,
		_onKeyDown: null,

		init() {
			fixPlaygroundAssets();
			this._onClick = this.onTargetClick.bind( this );
			this._onHover = this.onTargetHover.bind( this );
			this._onKeyDown = this.onKeyDown.bind( this );

			this.button = this.buildButton();
			this.overlay = this.buildOverlay();
			this.highlight = this.buildHighlight();
			this.backdrop = this.buildModal();

			document.body.appendChild( this.button );
			document.body.appendChild( this.overlay );
			document.body.appendChild( this.highlight );
			document.body.appendChild( this.backdrop );

			this.button.addEventListener(
				'click',
				this.enterTargeting.bind( this )
			);

			// Logged-in users have their account details available via tuftSettings.
			// Hide the name/email fields — they are still populated and submitted,
			// just not shown, since the user doesn't need to re-enter known info.
			if ( settings.isLoggedIn ) {
				this.backdrop.querySelector(
					'#tuft-user-fields'
				).style.display = 'none';
			}
		},

		// ── Build UI elements ──────────────────────────────────

		buildButton() {
			const btn = document.createElement( 'button' );
			btn.id = 'tuft-trigger';
			btn.setAttribute( 'aria-label', 'Leave feedback' );
			btn.title = 'Leave feedback';
			// PNG from brand kit: coral disc + puff mark at 2× pixel density.
			const img = document.createElement( 'img' );
			img.src = settings.buttonImg;
			img.alt = '';
			img.setAttribute( 'aria-hidden', 'true' );
			btn.appendChild( img );
			return btn;
		},

		buildOverlay() {
			const overlay = document.createElement( 'div' );
			overlay.id = 'tuft-overlay';
			const hint = document.createElement( 'div' );
			hint.id = 'tuft-overlay-hint';
			hint.innerHTML =
				'Click anywhere to place feedback &nbsp;&bull;&nbsp; <kbd>Esc</kbd> to cancel';
			overlay.appendChild( hint );
			return overlay;
		},

		buildHighlight() {
			const box = document.createElement( 'div' );
			box.id = 'tuft-highlight';
			box.setAttribute( 'aria-hidden', 'true' );
			return box;
		},

		buildModal() {
			const backdrop = document.createElement( 'div' );
			backdrop.id = 'tuft-modal-backdrop';
			backdrop.setAttribute( 'role', 'dialog' );
			backdrop.setAttribute( 'aria-modal', 'true' );
			backdrop.setAttribute( 'aria-label', 'Submit feedback' );

			backdrop.innerHTML = [
				'<div id="tuft-modal">',
				'  <div id="tuft-modal-header">',
				'    <h2>Leave Feedback</h2>',
				'    <button id="tuft-modal-close" aria-label="Close" title="Close">',
				'      <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
				'    </button>',
				'  </div>',
				'  <div id="tuft-modal-body">',
				'    <div id="tuft-success">',
				'      <div id="tuft-success-icon">',
				'        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
				'      </div>',
				'      <h3>Thanks for your feedback!</h3>',
				'      <p>Your feedback has been saved for review.</p>',
				'    </div>',
				'    <div id="tuft-form-wrap">',
				'      <img id="tuft-screenshot-preview" alt="Screenshot" />',
				'      <div id="tuft-element-info"></div>',
				'      <div id="tuft-error"></div>',
				'      <div id="tuft-user-fields">',
				'        <div class="tuft-field">',
				'          <label for="tuft-name">Name</label>',
				'          <input id="tuft-name" type="text" placeholder="Your name" autocomplete="name" />',
				'        </div>',
				'        <div class="tuft-field">',
				'          <label for="tuft-email">Email</label>',
				'          <input id="tuft-email" type="email" placeholder="your@email.com" autocomplete="email" />',
				'        </div>',
				'      </div>',
				'      <div class="tuft-field">',
				'        <label for="tuft-feedback">Feedback <span style="color:#ef4444">*</span></label>',
				'        <textarea id="tuft-feedback" placeholder="Describe what you\'re seeing or what could be improved…"></textarea>',
				'      </div>',
				'      <div id="tuft-modal-actions">',
				'        <button class="tuft-btn tuft-btn-secondary" id="tuft-cancel-btn" type="button">Cancel</button>',
				'        <button class="tuft-btn tuft-btn-primary" id="tuft-submit-btn" type="button">Submit Feedback</button>',
				'      </div>',
				'    </div>',
				'  </div>',
				'</div>',
			].join( '\n' );

			backdrop
				.querySelector( '#tuft-modal-close' )
				.addEventListener( 'click', this.closeModal.bind( this ) );
			backdrop
				.querySelector( '#tuft-cancel-btn' )
				.addEventListener( 'click', this.closeModal.bind( this ) );
			backdrop
				.querySelector( '#tuft-submit-btn' )
				.addEventListener( 'click', this.submit.bind( this ) );

			// Close on backdrop click outside modal card.
			backdrop.addEventListener(
				'click',
				function ( e ) {
					if ( e.target === backdrop ) {
						this.closeModal();
					}
				}.bind( this )
			);

			this.modal = backdrop;
			return backdrop;
		},

		// ── Targeting mode ─────────────────────────────────────

		enterTargeting() {
			fixPlaygroundAssets();
			this.targeting = true;
			this.button.style.display = 'none';
			this.overlay.classList.add( 'active' );
			document.body.classList.add( 'tuft-targeting' );
			document.addEventListener( 'mouseover', this._onHover, true );
			document.addEventListener( 'click', this._onClick, true );
			document.addEventListener( 'keydown', this._onKeyDown, true );
		},

		exitTargeting() {
			this.targeting = false;
			this.button.style.display = '';
			this.overlay.classList.remove( 'active' );
			this.highlight.style.display = 'none';
			document.body.classList.remove( 'tuft-targeting' );
			document.removeEventListener( 'mouseover', this._onHover, true );
			document.removeEventListener( 'click', this._onClick, true );
			document.removeEventListener( 'keydown', this._onKeyDown, true );
		},

		onTargetHover( e ) {
			const skip = [
				this.highlight,
				this.overlay,
				this.button,
				this.backdrop,
			];
			if (
				skip.some( function ( el ) {
					return el && ( e.target === el || el.contains( e.target ) );
				} )
			) {
				return;
			}

			const rect = e.target.getBoundingClientRect();
			const hl = this.highlight;
			hl.style.cssText = [
				'display:block',
				'top:' + rect.top + 'px',
				'left:' + rect.left + 'px',
				'width:' + rect.width + 'px',
				'height:' + rect.height + 'px',
			].join( ';' );
		},

		onTargetClick( e ) {
			// Don't intercept the trigger button itself.
			if (
				this.button &&
				( e.target === this.button || this.button.contains( e.target ) )
			) {
				return;
			}

			e.preventDefault();
			e.stopImmediatePropagation();

			const target = e.target;
			const selector = getSelector( target );
			const boundingRect = target.getBoundingClientRect();

			this.captured = {
				selector,
				xPercent: ( ( e.clientX / window.innerWidth ) * 100 ).toFixed(
					1
				),
				yPercent: ( ( e.clientY / window.innerHeight ) * 100 ).toFixed(
					1
				),
				rectLeft: (
					( boundingRect.left / window.innerWidth ) *
					100
				).toFixed( 2 ),
				rectTop: (
					( boundingRect.top / window.innerHeight ) *
					100
				).toFixed( 2 ),
				rectWidth: (
					( boundingRect.width / window.innerWidth ) *
					100
				).toFixed( 2 ),
				rectHeight: (
					( boundingRect.height / window.innerHeight ) *
					100
				).toFixed( 2 ),
				viewportWidth: window.innerWidth,
				viewportHeight: window.innerHeight,
				pageUrl: settings.pageUrl,
				pageTitle: document.title,
				formState: getFormState(),
				userAgent: navigator.userAgent,
				screenshot: null,
			};

			this.exitTargeting();

			// Allow two frames for the overlay to repaint away before screenshotting.
			const self = this;
			requestAnimationFrame( function () {
				requestAnimationFrame( function () {
					takeScreenshot().then( function ( dataUrl ) {
						self.captured.screenshot = dataUrl;
						self.openModal();
					} );
				} );
			} );
		},

		onKeyDown( e ) {
			if ( e.key === 'Escape' ) {
				this.exitTargeting();
			}
		},

		// ── Modal ──────────────────────────────────────────────

		openModal() {
			const data = this.captured;

			// Reset state
			const success = this.backdrop.querySelector( '#tuft-success' );
			const formWrap = this.backdrop.querySelector( '#tuft-form-wrap' );
			const errorEl = this.backdrop.querySelector( '#tuft-error' );
			const submitBtn = this.backdrop.querySelector( '#tuft-submit-btn' );
			success.classList.remove( 'visible' );
			formWrap.style.display = '';
			errorEl.classList.remove( 'visible' );
			errorEl.textContent = '';
			submitBtn.disabled = false;
			submitBtn.textContent = 'Submit Feedback';

			// Screenshot preview — annotated with spotlight and element bounds.
			const preview = this.backdrop.querySelector(
				'#tuft-screenshot-preview'
			);
			if ( data.screenshot ) {
				preview.src = data.screenshot;
				preview.classList.add( 'visible' );
				this.annotatePreview( preview, data );
			} else {
				preview.classList.remove( 'visible' );
			}

			// Element info
			const info = this.backdrop.querySelector( '#tuft-element-info' );
			if ( data.selector ) {
				info.innerHTML =
					'Element: <code>' + escapeHtml( data.selector ) + '</code>';
				info.classList.add( 'visible' );
			} else {
				info.classList.remove( 'visible' );
			}

			// Pre-fill user fields if logged in
			this.backdrop.querySelector( '#tuft-name' ).value =
				settings.userName || '';
			this.backdrop.querySelector( '#tuft-email' ).value =
				settings.userEmail || '';
			this.backdrop.querySelector( '#tuft-feedback' ).value = '';

			this.backdrop.classList.add( 'active' );

			// Focus feedback textarea
			setTimeout(
				function () {
					this.backdrop.querySelector( '#tuft-feedback' ).focus();
				}.bind( this ),
				50
			);
		},

		closeModal() {
			this.backdrop.classList.remove( 'active' );
		},

		// ── Submission ─────────────────────────────────────────

		submit() {
			const feedbackEl = this.backdrop.querySelector( '#tuft-feedback' );
			const errorEl = this.backdrop.querySelector( '#tuft-error' );

			const feedback = feedbackEl.value.trim();
			if ( ! feedback ) {
				errorEl.textContent =
					'Please describe your feedback before submitting.';
				errorEl.classList.add( 'visible' );
				feedbackEl.focus();
				return;
			}

			const nameEl = this.backdrop.querySelector( '#tuft-name' );
			const emailEl = this.backdrop.querySelector( '#tuft-email' );
			const submitBtn = this.backdrop.querySelector( '#tuft-submit-btn' );

			errorEl.classList.remove( 'visible' );
			submitBtn.disabled = true;
			submitBtn.textContent = 'Submitting…';

			const data = this.captured || {};
			const payload = {
				feedback,
				name: nameEl.value.trim(),
				email: emailEl.value.trim(),
				pageUrl: data.pageUrl || '',
				pageTitle: data.pageTitle || document.title,
				selector: data.selector || '',
				xPercent: data.xPercent || '',
				yPercent: data.yPercent || '',
				rectLeft: data.rectLeft ?? null,
				rectTop: data.rectTop ?? null,
				rectWidth: data.rectWidth ?? null,
				rectHeight: data.rectHeight ?? null,
				viewportWidth: data.viewportWidth || window.innerWidth,
				viewportHeight: data.viewportHeight || window.innerHeight,
				formState: data.formState || null,
				userAgent: data.userAgent || navigator.userAgent,
				screenshot: data.screenshot || null,
			};

			const self = this;
			fetch( settings.restUrl + '/submit', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': settings.nonce,
				},
				body: JSON.stringify( payload ),
			} )
				.then( function ( res ) {
					return res.json().then( function ( body ) {
						return { ok: res.ok, body };
					} );
				} )
				.then( function ( result ) {
					if ( result.ok && result.body.success ) {
						self.showSuccess();
					} else {
						const msg =
							( result.body && result.body.message ) ||
							'Something went wrong. Please try again.';
						errorEl.textContent = msg;
						errorEl.classList.add( 'visible' );
						submitBtn.disabled = false;
						submitBtn.textContent = 'Submit Feedback';
					}
				} )
				.catch( function () {
					errorEl.textContent =
						'Network error. Please check your connection and try again.';
					errorEl.classList.add( 'visible' );
					submitBtn.disabled = false;
					submitBtn.textContent = 'Submit Feedback';
				} );
		},

		/**
		 * Draw a spotlight annotation onto the screenshot preview.
		 *
		 * Mirrors the visual produced by DF_SVG_Annotation::build() on the PHP side:
		 * dark overlay with a circular spotlight cutout, a dashed element bounding box,
		 * and a ring-and-crosshair marker at the exact click point.
		 * The annotated result replaces the plain screenshot src on the preview <img>.
		 *
		 * @param {HTMLImageElement} preview The preview img element.
		 * @param {Object}           data    Captured data from onTargetClick.
		 */
		annotatePreview( preview, data ) {
			const xPct = parseFloat( data.xPercent );
			const yPct = parseFloat( data.yPercent );
			if ( isNaN( xPct ) || isNaN( yPct ) ) {
				return;
			}

			const img = new Image();
			img.onload = function () {
				const w = img.naturalWidth;
				const h = img.naturalHeight;
				const canvas = document.createElement( 'canvas' );
				canvas.width = w;
				canvas.height = h;
				const ctx = canvas.getContext( '2d' );

				ctx.drawImage( img, 0, 0 );

				const cx = ( xPct / 100 ) * w;
				const cy = ( yPct / 100 ) * h;
				const spotR = Math.min( w, h ) * 0.15;

				// Dark overlay with circular spotlight cutout (even-odd fill).
				ctx.fillStyle = 'rgba(0,0,0,0.6)';
				ctx.beginPath();
				ctx.rect( 0, 0, w, h );
				ctx.arc( cx, cy, spotR, 0, Math.PI * 2, true );
				ctx.fill( 'evenodd' );

				// Element bounding box: two-pass dashed rect matching the SVG style.
				const rl = parseFloat( data.rectLeft );
				const rt = parseFloat( data.rectTop );
				const rw = parseFloat( data.rectWidth );
				const rh = parseFloat( data.rectHeight );
				if (
					! isNaN( rl ) &&
					! isNaN( rt ) &&
					! isNaN( rw ) &&
					! isNaN( rh )
				) {
					const rx = ( rl / 100 ) * w;
					const ry = ( rt / 100 ) * h;
					const rW = ( rw / 100 ) * w;
					const rH = ( rh / 100 ) * h;

					ctx.setLineDash( [ 8, 4 ] );
					ctx.lineDashOffset = 0;
					ctx.strokeStyle = 'rgba(255,255,255,0.5)';
					ctx.lineWidth = 2.5;
					ctx.strokeRect( rx, ry, rW, rH );

					ctx.lineDashOffset = 4;
					ctx.strokeStyle = '#fbbf24';
					ctx.lineWidth = 1.5;
					ctx.strokeRect( rx, ry, rW, rH );

					ctx.setLineDash( [] );
					ctx.lineDashOffset = 0;
				}

				// Ring: white halo then red stroke.
				ctx.beginPath();
				ctx.arc( cx, cy, 18, 0, Math.PI * 2 );
				ctx.strokeStyle = 'rgba(255,255,255,0.9)';
				ctx.lineWidth = 4;
				ctx.stroke();
				ctx.strokeStyle = '#ef4444';
				ctx.lineWidth = 2;
				ctx.stroke();

				// Crosshair arms — white pass then red pass.
				const gap = 22,
					arm = 14;
				const arms = [
					[ cx - gap - arm, cy, cx - gap, cy ],
					[ cx + gap, cy, cx + gap + arm, cy ],
					[ cx, cy - gap - arm, cx, cy - gap ],
					[ cx, cy + gap, cx, cy + gap + arm ],
				];
				[
					[ 'rgba(255,255,255,0.9)', 3 ],
					[ '#ef4444', 1.5 ],
				].forEach( function ( [ color, width ] ) {
					ctx.strokeStyle = color;
					ctx.lineWidth = width;
					arms.forEach( function ( [ x1, y1, x2, y2 ] ) {
						ctx.beginPath();
						ctx.moveTo( x1, y1 );
						ctx.lineTo( x2, y2 );
						ctx.stroke();
					} );
				} );

				// Centre dot: red with white core.
				ctx.fillStyle = '#ef4444';
				ctx.beginPath();
				ctx.arc( cx, cy, 4, 0, Math.PI * 2 );
				ctx.fill();
				ctx.fillStyle = 'white';
				ctx.beginPath();
				ctx.arc( cx, cy, 2, 0, Math.PI * 2 );
				ctx.fill();

				preview.src = canvas.toDataURL( 'image/jpeg', 0.85 );
			};
			img.src = data.screenshot;
		},

		showSuccess() {
			this.backdrop.querySelector( '#tuft-form-wrap' ).style.display =
				'none';
			this.backdrop
				.querySelector( '#tuft-success' )
				.classList.add( 'visible' );
			const self = this;
			setTimeout( function () {
				self.closeModal();
			}, 2500 );
		},
	};

	// ── Utility ────────────────────────────────────────────────

	function escapeHtml( str ) {
		return str
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	// ── Boot ───────────────────────────────────────────────────

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			DF.init();
		} );
	} else {
		DF.init();
	}
} )();
