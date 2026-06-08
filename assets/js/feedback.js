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
		drawCanvas: null,

		// Drawing state — reset each time the modal opens.
		drawing: {
			active: false,
			tool: 'pen', // 'pen' | 'rect'
			strokes: [], // committed strokes
			penPoints: [], // points for the stroke currently being drawn
			baseSnapshot: null, // ImageData after spotlight annotation, before user marks
			startX: 0,
			startY: 0,
		},

		// Bound event handlers (stored for removeEventListener)
		_onClick: null,
		_onHover: null,
		_onKeyDown: null,
		_onDrawStart: null,
		_onDrawMove: null,
		_onDrawEnd: null,

		init() {
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
				'      <canvas id="tuft-screenshot-canvas" aria-label="Annotated screenshot — draw on it to mark up areas"></canvas>',
				'      <div id="tuft-draw-toolbar" role="toolbar" aria-label="Drawing tools">',
				'        <button class="tuft-draw-tool tuft-draw-active" data-tool="pen" title="Pen (freehand)" aria-pressed="true">',
				'          <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>',
				'        </button>',
				'        <button class="tuft-draw-tool" data-tool="rect" title="Rectangle" aria-pressed="false">',
				'          <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/></svg>',
				'        </button>',
				'        <div class="tuft-draw-sep" aria-hidden="true"></div>',
				'        <button id="tuft-draw-undo" title="Undo last stroke" aria-label="Undo">',
				'          <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7v6h6"/><path d="M21 17a9 9 0 0 0-9-9 9 9 0 0 0-6 2.3L3 13"/></svg>',
				'        </button>',
				'        <button id="tuft-draw-clear" title="Clear all drawings" aria-label="Clear drawings">',
				'          <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>',
				'        </button>',
				'      </div>',
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

			// Wire drawing toolbar tool buttons.
			const toolBtns = backdrop.querySelectorAll( '.tuft-draw-tool' );
			toolBtns.forEach(
				function ( btn ) {
					btn.addEventListener(
						'click',
						function () {
							toolBtns.forEach( function ( b ) {
								b.classList.remove( 'tuft-draw-active' );
								b.setAttribute( 'aria-pressed', 'false' );
							} );
							btn.classList.add( 'tuft-draw-active' );
							btn.setAttribute( 'aria-pressed', 'true' );
							this.drawing.tool = btn.dataset.tool;
						}.bind( this )
					);
				}.bind( this )
			);

			backdrop
				.querySelector( '#tuft-draw-undo' )
				.addEventListener( 'click', this.undoStroke.bind( this ) );
			backdrop
				.querySelector( '#tuft-draw-clear' )
				.addEventListener( 'click', this.clearDrawing.bind( this ) );

			this.drawCanvas = backdrop.querySelector(
				'#tuft-screenshot-canvas'
			);
			this.modal = backdrop;
			return backdrop;
		},

		// ── Targeting mode ─────────────────────────────────────

		enterTargeting() {
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

			// Reset drawing tool buttons to pen.
			this.backdrop
				.querySelectorAll( '.tuft-draw-tool' )
				.forEach( function ( b ) {
					const isPen = b.dataset.tool === 'pen';
					b.classList.toggle( 'tuft-draw-active', isPen );
					b.setAttribute( 'aria-pressed', isPen ? 'true' : 'false' );
				} );
			this.drawing.tool = 'pen';

			// Screenshot canvas — set up asynchronously once the screenshot image loads.
			const canvas = this.drawCanvas;
			const toolbar = this.backdrop.querySelector( '#tuft-draw-toolbar' );
			if ( data.screenshot ) {
				canvas.classList.add( 'visible' );
				this.setupDrawCanvas( data );
			} else {
				canvas.classList.remove( 'visible' );
				toolbar.style.display = 'none';
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
			this.teardownDrawCanvas();
		},

		// ── Drawing canvas setup / teardown ────────────────────

		/**
		 * Load the raw screenshot into the draw canvas, paint the spotlight +
		 * crosshair annotation on top, then save that as the base snapshot so
		 * user strokes can be undone without re-running html2canvas.
		 *
		 * Runs asynchronously because it needs to wait for the image to load.
		 */
		setupDrawCanvas( data ) {
			const canvas = this.drawCanvas;
			const ctx = canvas.getContext( '2d' );
			const toolbar = this.backdrop.querySelector( '#tuft-draw-toolbar' );

			// Hide toolbar until the canvas is ready.
			toolbar.style.display = 'none';

			const img = new Image();
			img.onload = function () {
				// Scale screenshot to fill the canvas's CSS display width, preserving
				// aspect ratio. The canvas's offsetWidth is reliable here because
				// openModal() has already added the 'visible' class (display:block).
				const w = canvas.offsetWidth || 440;
				const h = Math.round(
					( img.naturalHeight / img.naturalWidth ) * w
				);
				canvas.width = w;
				canvas.height = h;

				// Draw the raw screenshot scaled to canvas dimensions.
				ctx.drawImage( img, 0, 0, w, h );

				// Paint spotlight + crosshair annotation.
				const xPct = parseFloat( data.xPercent );
				const yPct = parseFloat( data.yPercent );

				if ( ! isNaN( xPct ) && ! isNaN( yPct ) ) {
					const cx = ( xPct / 100 ) * w;
					const cy = ( yPct / 100 ) * h;
					const spotR = Math.min( w, h ) * 0.15;

					// Dark overlay with circular spotlight cutout (even-odd fill).
					ctx.fillStyle = 'rgba(0,0,0,0.6)';
					ctx.beginPath();
					ctx.rect( 0, 0, w, h );
					ctx.arc( cx, cy, spotR, 0, Math.PI * 2, true );
					ctx.fill( 'evenodd' );

					// Element bounding box: two-pass dashed rect.
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

					// Ring: white halo then coral stroke.
					ctx.beginPath();
					ctx.arc( cx, cy, 18, 0, Math.PI * 2 );
					ctx.strokeStyle = 'rgba(255,255,255,0.9)';
					ctx.lineWidth = 4;
					ctx.stroke();
					ctx.strokeStyle = '#ef4444';
					ctx.lineWidth = 2;
					ctx.stroke();

					// Crosshair arms — white pass then coral pass.
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
					].forEach( function ( pair ) {
						ctx.strokeStyle = pair[ 0 ];
						ctx.lineWidth = pair[ 1 ];
						arms.forEach( function ( arm ) {
							ctx.beginPath();
							ctx.moveTo( arm[ 0 ], arm[ 1 ] );
							ctx.lineTo( arm[ 2 ], arm[ 3 ] );
							ctx.stroke();
						} );
					} );

					// Centre dot.
					ctx.fillStyle = '#ef4444';
					ctx.beginPath();
					ctx.arc( cx, cy, 4, 0, Math.PI * 2 );
					ctx.fill();
					ctx.fillStyle = 'white';
					ctx.beginPath();
					ctx.arc( cx, cy, 2, 0, Math.PI * 2 );
					ctx.fill();
				}

				// Snapshot the fully-annotated base so Undo can restore it.
				this.drawing.baseSnapshot = ctx.getImageData( 0, 0, w, h );
				this.drawing.strokes = [];
				this.drawing.penPoints = [];
				this.drawing.active = false;

				// Show toolbar and attach drawing event listeners.
				toolbar.style.display = 'flex';
				this._onDrawStart = this.startDraw.bind( this );
				this._onDrawMove = this.moveDraw.bind( this );
				this._onDrawEnd = this.endDraw.bind( this );
				canvas.addEventListener( 'pointerdown', this._onDrawStart );
				canvas.addEventListener( 'pointermove', this._onDrawMove );
				canvas.addEventListener( 'pointerup', this._onDrawEnd );
				canvas.addEventListener( 'pointerleave', this._onDrawEnd );
			}.bind( this );

			img.src = data.screenshot;
		},

		teardownDrawCanvas() {
			const canvas = this.drawCanvas;
			if ( canvas && this._onDrawStart ) {
				canvas.removeEventListener( 'pointerdown', this._onDrawStart );
				canvas.removeEventListener( 'pointermove', this._onDrawMove );
				canvas.removeEventListener( 'pointerup', this._onDrawEnd );
				canvas.removeEventListener( 'pointerleave', this._onDrawEnd );
			}
			this._onDrawStart = null;
			this._onDrawMove = null;
			this._onDrawEnd = null;
			this.drawing.baseSnapshot = null;
			this.drawing.strokes = [];
			this.drawing.penPoints = [];
			this.drawing.active = false;
		},

		// ── Drawing primitives ─────────────────────────────────

		getDrawXY( e ) {
			const rect = this.drawCanvas.getBoundingClientRect();
			return {
				x: e.clientX - rect.left,
				y: e.clientY - rect.top,
			};
		},

		startDraw( e ) {
			e.preventDefault();
			this.drawing.active = true;
			const pos = this.getDrawXY( e );
			this.drawing.startX = pos.x;
			this.drawing.startY = pos.y;
			this.drawing.penPoints = [ pos ];

			if ( this.drawing.tool === 'pen' ) {
				const ctx = this.drawCanvas.getContext( '2d' );
				ctx.beginPath();
				ctx.moveTo( pos.x, pos.y );
			}
		},

		moveDraw( e ) {
			if ( ! this.drawing.active ) {
				return;
			}
			e.preventDefault();
			const pos = this.getDrawXY( e );
			const ctx = this.drawCanvas.getContext( '2d' );

			if ( this.drawing.tool === 'pen' ) {
				this.drawing.penPoints.push( pos );
				ctx.lineTo( pos.x, pos.y );
				ctx.strokeStyle = '#ef4444';
				ctx.lineWidth = 2.5;
				ctx.lineCap = 'round';
				ctx.lineJoin = 'round';
				ctx.stroke();
			} else if ( this.drawing.tool === 'rect' ) {
				// Redraw base + committed strokes, then draw the live rectangle.
				this.redrawCanvas();
				ctx.strokeStyle = '#ef4444';
				ctx.lineWidth = 2.5;
				ctx.lineCap = 'round';
				ctx.setLineDash( [] );
				ctx.strokeRect(
					this.drawing.startX,
					this.drawing.startY,
					pos.x - this.drawing.startX,
					pos.y - this.drawing.startY
				);
			}
		},

		endDraw( e ) {
			if ( ! this.drawing.active ) {
				return;
			}
			this.drawing.active = false;
			const pos = this.getDrawXY( e );

			if (
				this.drawing.tool === 'pen' &&
				this.drawing.penPoints.length > 1
			) {
				this.drawing.strokes.push( {
					type: 'pen',
					points: this.drawing.penPoints.slice(),
				} );
			} else if ( this.drawing.tool === 'rect' ) {
				const dx = pos.x - this.drawing.startX;
				const dy = pos.y - this.drawing.startY;
				// Ignore tiny accidental drags.
				if ( Math.abs( dx ) > 3 || Math.abs( dy ) > 3 ) {
					this.drawing.strokes.push( {
						type: 'rect',
						x: this.drawing.startX,
						y: this.drawing.startY,
						w: dx,
						h: dy,
					} );
				}
			}

			this.drawing.penPoints = [];
			this.redrawCanvas();
		},

		redrawCanvas() {
			const canvas = this.drawCanvas;
			const ctx = canvas.getContext( '2d' );
			if ( this.drawing.baseSnapshot ) {
				ctx.putImageData( this.drawing.baseSnapshot, 0, 0 );
			}
			this.drawing.strokes.forEach(
				function ( stroke ) {
					this.renderStroke( ctx, stroke );
				}.bind( this )
			);
		},

		renderStroke( ctx, stroke ) {
			ctx.strokeStyle = '#ef4444';
			ctx.lineWidth = 2.5;
			ctx.lineCap = 'round';
			ctx.lineJoin = 'round';
			ctx.setLineDash( [] );

			if ( stroke.type === 'pen' ) {
				ctx.beginPath();
				ctx.moveTo( stroke.points[ 0 ].x, stroke.points[ 0 ].y );
				for ( let i = 1; i < stroke.points.length; i++ ) {
					ctx.lineTo( stroke.points[ i ].x, stroke.points[ i ].y );
				}
				ctx.stroke();
			} else if ( stroke.type === 'rect' ) {
				ctx.strokeRect( stroke.x, stroke.y, stroke.w, stroke.h );
			}
		},

		undoStroke() {
			if ( ! this.drawing.strokes.length ) {
				return;
			}
			this.drawing.strokes.pop();
			this.redrawCanvas();
		},

		clearDrawing() {
			if ( ! this.drawing.strokes.length ) {
				return;
			}
			this.drawing.strokes = [];
			this.redrawCanvas();
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

			// If the draw canvas has a base snapshot, export its current state
			// (screenshot + spotlight annotation + any user drawings) as the
			// submitted screenshot. Fall back to the raw screenshot if the canvas
			// was never initialised (e.g. html2canvas unavailable).
			let screenshotData = data.screenshot || null;
			if ( this.drawing.baseSnapshot && this.drawCanvas ) {
				screenshotData = this.drawCanvas.toDataURL(
					'image/jpeg',
					0.85
				);
			}

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
				screenshot: screenshotData,
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
