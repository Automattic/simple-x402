import { createInterpolateElement, createRoot, useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardBody,
	CardFooter,
	CardHeader,
	TextControl,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
	__experimentalText as Text,
} from '@wordpress/components';
import { DataForm } from '@wordpress/dataviews';

import './style.scss';

const config = window.simpleX402Settings;

const boltIcon = (
	<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false">
		<path d="M13 2 4 14h7l-1 8 9-12h-7l1-8z" fill="currentColor" />
	</svg>
);

async function saveFields( partial ) {
	const body = new FormData();
	body.append( 'action', config.saveSettings.action );
	body.append( 'nonce', config.saveSettings.nonce );
	body.append( 'fields', JSON.stringify( partial ) );
	const resp = await fetch( config.ajaxUrl, {
		method: 'POST',
		credentials: 'same-origin',
		body,
	} );
	// A reverse-proxy or PHP fatal returns an HTML page, not JSON. Parse
	// defensively so the UI surfaces a clean "save_failed_http_502" instead
	// of a raw SyntaxError.
	let json = null;
	try {
		json = await resp.json();
	} catch ( _ ) {
		throw new Error( `save_failed_http_${ resp.status }` );
	}
	if ( ! resp.ok || ! json?.success ) {
		throw new Error( json?.data?.error || `save_failed_http_${ resp.status }` );
	}
	return json.data;
}

const PROBE_HEADER = 'X-Simple-X402-Probe';

/**
 * @param {{ url: string, nonce: string }} probe
 * @returns {Promise<string|null>} null if the response looks like a healthy paywall 402 JSON.
 */
async function runPaywallProbe( probe ) {
	const resp = await fetch( probe.url, {
		method: 'GET',
		credentials: 'same-origin',
		cache: 'no-store',
		headers: { [ PROBE_HEADER ]: probe.nonce },
	} );
	const ct = resp.headers.get( 'content-type' ) || '';
	if ( resp.status !== 402 || ! ct.includes( 'json' ) ) {
		return sprintf(
			/* translators: %s: HTTP status code or "unknown". */
			__( 'Paywall probe: expected HTTP 402 with JSON, got status %s.', 'simple-x402' ),
			String( resp.status )
		);
	}
	try {
		await resp.json();
	} catch ( _ ) {
		return __( 'Paywall probe: response was not valid JSON.', 'simple-x402' );
	}
	return null;
}

async function fetchPaywallProbeDescriptorFromServer() {
	const body = new FormData();
	body.append( 'action', config.paywallProbe.action );
	body.append( 'nonce', config.paywallProbe.nonce );
	const resp = await fetch( config.ajaxUrl, {
		method: 'POST',
		credentials: 'same-origin',
		body,
	} );
	let json = null;
	try {
		json = await resp.json();
	} catch ( _ ) {
		throw new Error( `probe_descriptor_failed_http_${ resp.status }` );
	}
	if ( ! resp.ok || ! json?.success ) {
		throw new Error( json?.data?.error || `probe_descriptor_failed_http_${ resp.status }` );
	}
	return json.data;
}

/**
 * Shared result line for facilitator "Test connection" and paywall self-check.
 *
 * @param {object} props
 * @param {boolean} props.pending
 * @param {boolean} [props.success]
 * @param {number} [props.durationMs]
 * @param {string} [props.failureMessage]
 * @param {string} [props.infoMessage] Skipped / informational (no ✓/✗).
 */
function DiagnosticProbeLine( { pending, success, durationMs, failureMessage, infoMessage } ) {
	if ( pending ) {
		return (
			<Text size={ 13 } variant="muted">
				{ __( 'Running check…', 'simple-x402' ) }
			</Text>
		);
	}
	if ( infoMessage ) {
		return (
			<Text size={ 13 } variant="muted">
				{ infoMessage }
			</Text>
		);
	}
	if ( success ) {
		return (
			<Text size={ 13 } variant="muted">
				{ durationMs != null
					? sprintf(
						/* translators: %d: round-trip time in milliseconds. */
						__( '✓ Succeeded in %dms', 'simple-x402' ),
						durationMs
					)
					: __( '✓ Succeeded', 'simple-x402' ) }
			</Text>
		);
	}
	if ( failureMessage ) {
		return (
			<Text size={ 13 } variant="muted">
				{ `✗ ${ failureMessage }` }
			</Text>
		);
	}
	return null;
}

function SaveFooter( { disabled, saving, error, onSave } ) {
	return (
		<CardFooter className="simple-x402-page__card-footer">
			<HStack spacing={ 3 } justify="flex-start">
				<Button
					variant="primary"
					type="button"
					onClick={ onSave }
					disabled={ disabled || saving }
					accessibleWhenDisabled
				>
					{ saving ? __( 'Saving…', 'simple-x402' ) : __( 'Save', 'simple-x402' ) }
				</Button>
				{ error && (
					<Text size={ 13 } variant="muted">
						{ `✗ ${ error }` }
					</Text>
				) }
			</HStack>
		</CardFooter>
	);
}

const isShallowEqual = ( a, b ) => {
	if ( a === b ) return true;
	if ( ! a || ! b ) return false;
	const keys = new Set( [ ...Object.keys( a ), ...Object.keys( b ) ] );
	for ( const k of keys ) {
		if ( String( a[ k ] ?? '' ) !== String( b[ k ] ?? '' ) ) return false;
	}
	return true;
};

// Per-card save loop: wraps an async call with `saving`/`error` tracking.
// The caller passes a function that returns a Promise; this hook runs it and
// surfaces the status. Doesn't touch local state — the caller handles syncing
// its own edits to the server response inside the callback.
function useSave() {
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( null );
	const run = async ( fn ) => {
		setSaving( true );
		setError( null );
		try {
			await fn();
		} catch ( e ) {
			setError( e instanceof Error ? e.message : String( e ) );
		} finally {
			setSaving( false );
		}
	};
	return { saving, error, run };
}

function CardTitle( { title, subtitle } ) {
	return (
		<VStack spacing={ 1 }>
			<Text size={ 14 } weight={ 600 }>
				{ title }
			</Text>
			{ subtitle && (
				<Text size={ 13 } variant="muted">
					{ subtitle }
				</Text>
			) }
		</VStack>
	);
}

const PAYWALL_MODE_FIELDS = [
	{
		id: 'paywallMode',
		label: '',
		type: 'text',
		Edit: 'radio',
		elements: [
			{ value: config.modes.paywall.none, label: __( 'No posts (paywall disabled)', 'simple-x402' ) },
			{ value: config.modes.paywall.allPosts, label: __( 'Every published post', 'simple-x402' ) },
			{ value: config.modes.paywall.category, label: __( 'Only posts in a specific category', 'simple-x402' ) },
		],
	},
	{
		id: 'termId',
		label: __( 'Category', 'simple-x402' ),
		type: 'text',
		Edit: 'select',
		elements: config.categories.map( ( c ) => ( {
			value: String( c.term_id ),
			label: c.name,
		} ) ),
		isDisabled: ( { item } ) => item.paywallMode !== config.modes.paywall.category,
	},
];

function PaywallScopeCard( { saved, save } ) {
	const [ paywallMode, setPaywallMode ] = useState( saved.paywall_mode );
	const [ termId, setTermId ] = useState( saved.paywall_category_term_id );
	const [ wallProbePending, setWallProbePending ] = useState( false );
	const [ wallProbe, setWallProbe ] = useState( null );
	const wallProbeRequestId = useRef( 0 );
	const { saving, error, run } = useSave();

	const isDirty =
		paywallMode !== saved.paywall_mode ||
		Number( termId ) !== Number( saved.paywall_category_term_id );

	const paywallTestDisabled =
		isDirty || saved.paywall_mode === config.modes.paywall.none;

	const runPaywallProbeFollowThrough = async ( probeBlock, rid, mergedSnapshot ) => {
		if ( probeBlock == null ) {
			if ( rid !== wallProbeRequestId.current ) {
				return;
			}
			setWallProbe( {
				infoMessage: __( 'Paywall mode is off — no live probe run.', 'simple-x402' ),
			} );
			return;
		}
		if ( probeBlock?.reason === 'no_matching_post' ) {
			if ( rid !== wallProbeRequestId.current ) {
				return;
			}
			setWallProbe( {
				infoMessage: __(
					'Paywall probe skipped: no published post matches the current scope.',
					'simple-x402'
				),
			} );
			return;
		}
		if ( ! probeBlock?.url || ! probeBlock?.nonce ) {
			return;
		}
		if ( ! String( mergedSnapshot.selected_facilitator_id ?? '' ).trim() ) {
			if ( rid !== wallProbeRequestId.current ) {
				return;
			}
			setWallProbe( {
				infoMessage: __(
					'Paywall probe skipped: choose a facilitator so the paywall can respond.',
					'simple-x402'
				),
			} );
			return;
		}

		setWallProbePending( true );
		const t0 = performance.now();
		try {
			const err = await runPaywallProbe( {
				url: probeBlock.url,
				nonce: probeBlock.nonce,
			} );
			if ( rid !== wallProbeRequestId.current ) {
				return;
			}
			const durationMs = Math.round( performance.now() - t0 );
			if ( err ) {
				setWallProbe( { failureMessage: err } );
			} else {
				setWallProbe( { success: true, durationMs } );
			}
		} catch ( e ) {
			if ( rid !== wallProbeRequestId.current ) {
				return;
			}
			const detail = e instanceof Error ? e.message : String( e );
			setWallProbe( {
				failureMessage: sprintf(
					/* translators: %s: Error detail (e.g. network failure). */
					__( 'Paywall probe failed: %s', 'simple-x402' ),
					detail
				),
			} );
		} finally {
			if ( rid === wallProbeRequestId.current ) {
				setWallProbePending( false );
			}
		}
	};

	const onTestPaywall = () =>
		run( async () => {
			const rid = ++wallProbeRequestId.current;
			setWallProbe( null );
			setWallProbePending( false );
			try {
				const wrap = await fetchPaywallProbeDescriptorFromServer();
				await runPaywallProbeFollowThrough( wrap.probe, rid, saved );
			} catch ( e ) {
				if ( rid !== wallProbeRequestId.current ) {
					return;
				}
				setWallProbe( {
					failureMessage: e instanceof Error ? e.message : String( e ),
				} );
			}
		} );

	const onSave = () =>
		run( async () => {
			const rid = ++wallProbeRequestId.current;
			setWallProbe( null );
			setWallProbePending( false );

			const { values: merged, ajaxData: data } = await save( {
				paywall_mode: paywallMode,
				paywall_category_term_id: termId,
			} );
			setPaywallMode( merged.paywall_mode );
			setTermId( merged.paywall_category_term_id );

			if ( ! Object.prototype.hasOwnProperty.call( data, 'probe' ) ) {
				return;
			}
			await runPaywallProbeFollowThrough( data.probe, rid, merged );
		} );

	return (
		<Card>
			<CardHeader>
				<CardTitle
					title={ __( 'Posts', 'simple-x402' ) }
					subtitle={ __( 'Which posts should be paywalled?', 'simple-x402' ) }
				/>
			</CardHeader>
			<CardBody>
				<DataForm
					data={ { paywallMode, termId: String( termId ) } }
					fields={ PAYWALL_MODE_FIELDS }
					form={ {
						layout: { type: 'regular', labelPosition: 'none' },
						fields: [
							'paywallMode',
							{ id: 'termId', layout: { type: 'regular', labelPosition: 'top' } },
						],
					} }
					onChange={ ( edits ) => {
						wallProbeRequestId.current++;
						setWallProbe( null );
						setWallProbePending( false );
						if ( 'paywallMode' in edits ) setPaywallMode( edits.paywallMode );
						if ( 'termId' in edits ) setTermId( Number( edits.termId ) );
					} }
				/>
				<HStack spacing={ 3 } justify="flex-start" className="simple-x402-page__probe-row">
					<Button
						variant="secondary"
						size="compact"
						type="button"
						icon={ boltIcon }
						iconSize={ 16 }
						onClick={ onTestPaywall }
						disabled={ paywallTestDisabled || wallProbePending || saving }
						accessibleWhenDisabled
					>
						{ wallProbePending
							? __( 'Running check…', 'simple-x402' )
							: __( 'Test paywall response', 'simple-x402' ) }
					</Button>
					{ ( wallProbePending || wallProbe ) && (
						<DiagnosticProbeLine
							pending={ wallProbePending }
							success={ wallProbe?.success === true }
							durationMs={ wallProbe?.durationMs }
							failureMessage={ wallProbe?.failureMessage }
							infoMessage={ wallProbe?.infoMessage }
						/>
					) }
				</HStack>
			</CardBody>
			<SaveFooter disabled={ ! isDirty } saving={ saving } error={ error } onSave={ onSave } />
		</Card>
	);
}

const AUDIENCE_FIELDS = [
	{
		id: 'audience',
		label: '',
		type: 'text',
		Edit: 'radio',
		elements: [
			{ value: config.modes.audience.everyone, label: __( 'Everyone (humans and bots)', 'simple-x402' ) },
			{ value: config.modes.audience.bots, label: __( 'Only detected bots and crawlers', 'simple-x402' ) },
		],
	},
];

function AudienceCard( { saved, save } ) {
	const [ audience, setAudience ] = useState( saved.paywall_audience );
	const { saving, error, run } = useSave();
	const isDirty = audience !== saved.paywall_audience;

	const onSave = () =>
		run( async () => {
			const { values: merged } = await save( { paywall_audience: audience } );
			setAudience( merged.paywall_audience );
		} );

	return (
		<Card>
			<CardHeader>
				<CardTitle
					title={ __( 'Audience', 'simple-x402' ) }
					subtitle={ __( 'Which visitors should see the paywall?', 'simple-x402' ) }
				/>
			</CardHeader>
			<CardBody>
				<DataForm
					data={ { audience } }
					fields={ AUDIENCE_FIELDS }
					form={ {
						layout: { type: 'regular', labelPosition: 'none' },
						fields: [ 'audience' ],
					} }
					onChange={ ( edits ) => setAudience( edits.audience ) }
				/>
			</CardBody>
			<SaveFooter disabled={ ! isDirty } saving={ saving } error={ error } onSave={ onSave } />
		</Card>
	);
}

const PRICING_FIELDS = [
	{
		id: 'default_price',
		// USDC has 6 on-chain decimals, so prices down to 0.000001 are valid.
		// A type='number' input would need a matching step attribute and
		// would still reject pasted values that don't land on the grid; a
		// plain text input lets site owners type any decimal and leans on
		// the server sanitizer to reject non-numeric or non-positive input.
		label: __( 'Price per request (USDC)', 'simple-x402' ),
		type: 'text',
		placeholder: '0.01',
	},
];

function PricingCard( { saved, save } ) {
	const [ price, setPrice ] = useState( saved.default_price || '' );
	const { saving, error, run } = useSave();
	const isDirty = String( price ?? '' ) !== String( saved.default_price ?? '' );

	const onSave = () =>
		run( async () => {
			const { values: merged } = await save( { default_price: price } );
			setPrice( merged.default_price );
		} );

	return (
		<Card>
			<CardHeader>
				<CardTitle
					title={ __( 'Pricing', 'simple-x402' ) }
					subtitle={ __( 'How much each paywalled request costs, in USDC.', 'simple-x402' ) }
				/>
			</CardHeader>
			<CardBody>
				<DataForm
					data={ { default_price: price } }
					fields={ PRICING_FIELDS }
					form={ {
						layout: { type: 'regular', labelPosition: 'top' },
						fields: [ 'default_price' ],
					} }
					onChange={ ( edits ) => setPrice( edits.default_price ) }
				/>
			</CardBody>
			<SaveFooter disabled={ ! isDirty } saving={ saving } error={ error } onSave={ onSave } />
		</Card>
	);
}

function facilitatorOptions() {
	const entries = ( config.facilitators || [] ).map( ( f ) => ( {
		value: f.id,
		label: f.name || f.id,
	} ) );
	return [
		{ value: '', label: __( '— Select a facilitator —', 'simple-x402' ) },
		...entries,
	];
}

const FACILITATOR_FIELDS = [
	{
		id: 'facilitator',
		label: __( 'Facilitator', 'simple-x402' ),
		type: 'text',
		Edit: 'select',
		elements: facilitatorOptions(),
	},
];

// EVM address: 0x followed by exactly 40 hex characters. Checksum (EIP-55)
// is intentionally not enforced — the facilitator's /verify call rejects
// addresses the chain doesn't recognise, which is a stricter guarantee than
// any client-side check. This regex catches typos, wrong length, and bad
// characters, which is what local validation is for.
const WALLET_RE = /^0x[0-9a-fA-F]{40}$/;

/** @see https://ethereum.org/guides/how-to-create-an-ethereum-account/ */
const ETHEREUM_ACCOUNT_GUIDE_URL =
	'https://ethereum.org/guides/how-to-create-an-ethereum-account/';

const emptySlot = () => ( { wallet_address: '' } );

function FacilitatorCard( { saved, save } ) {
	const [ facilitator, setFacilitator ] = useState( saved.selected_facilitator_id || '' );
	const [ slots, setSlots ] = useState( saved.facilitators || {} );
	const [ probe, setProbe ] = useState( null );
	const [ testing, setTesting ] = useState( false );
	// Every new probe bumps this ref; late-arriving fetches check it and
	// drop their result if the user changed the picker or started a new
	// probe in the meantime. Without this, switching facilitators mid-probe
	// could paint a stale "✓ Succeeded" against the wrong option.
	const probeRequestId = useRef( 0 );
	const { saving, error, run } = useSave();

	const slot = '' === facilitator ? emptySlot() : ( slots[ facilitator ] ?? emptySlot() );
	const savedSlot = '' === facilitator
		? emptySlot()
		: ( ( saved.facilitators || {} )[ facilitator ] ?? emptySlot() );
	const savedId = saved.selected_facilitator_id || '';

	const walletValue = slot.wallet_address || '';
	const trimmedWallet = walletValue.trim();
	const walletHasInvalidFormat =
		'' !== facilitator && '' !== trimmedWallet && ! WALLET_RE.test( trimmedWallet );
	const walletError = walletHasInvalidFormat
		? __( 'Enter a valid address — 0x followed by 40 hex characters.', 'simple-x402' )
		: null;
	const walletHelp =
		'' !== facilitator
			? createInterpolateElement(
					__(
						'Required to accept payments. <a>How to create an Ethereum account</a> — guide on ethereum.org.',
						'simple-x402'
					),
					{
						a: (
							<a
								href={ ETHEREUM_ACCOUNT_GUIDE_URL }
								target="_blank"
								rel="noopener noreferrer"
							/>
						),
					}
				)
			: null;

	const isDirty =
		facilitator !== savedId ||
		( '' !== facilitator && ! isShallowEqual( slot, savedSlot ) );

	const runTest = async () => {
		if ( ! facilitator ) {
			return;
		}
		const requestId = ++probeRequestId.current;
		setTesting( true );
		setProbe( null );
		try {
			const body = new FormData();
			body.append( 'action', config.testConnection.action );
			body.append( 'nonce', config.testConnection.nonce );
			body.append( 'connector_id', facilitator );
			const resp = await fetch( config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body,
			} );
			const json = await resp.json();
			if ( requestId !== probeRequestId.current ) return;
			setProbe( json.success ? json.data : { ok: false, error: json.data?.error || 'request_failed' } );
		} catch ( e ) {
			if ( requestId !== probeRequestId.current ) return;
			setProbe( { ok: false, error: String( e ) } );
		} finally {
			if ( requestId === probeRequestId.current ) setTesting( false );
		}
	};

	const onWalletChange = ( edits ) => {
		setSlots( {
			...slots,
			[ facilitator ]: { ...slot, ...edits },
		} );
	};

	const onSave = () =>
		run( async () => {
			const partial = { selected_facilitator_id: facilitator };
			if ( '' !== facilitator ) {
				partial.facilitators = { [ facilitator ]: slot };
			}
			const { values: merged } = await save( partial );
			setFacilitator( merged.selected_facilitator_id || '' );
			setSlots( merged.facilitators || {} );
			if ( facilitator ) {
				await runTest();
			}
		} );

	return (
		<Card>
			<CardHeader>
				<CardTitle
					title={ __( 'Facilitator', 'simple-x402' ) }
					subtitle={ __(
						'Where verify and settle requests are sent, and where payments land. The paywall stays inert until a receiving wallet is set.',
						'simple-x402'
					) }
				/>
			</CardHeader>
			<CardBody>
				<DataForm
					data={ { facilitator } }
					fields={ FACILITATOR_FIELDS }
					form={ {
						layout: { type: 'regular', labelPosition: 'top' },
						fields: [ 'facilitator' ],
					} }
					onChange={ ( edits ) => {
						setFacilitator( edits.facilitator );
						setProbe( null );
						// Invalidate any in-flight probe so its response
						// doesn't paint onto the newly-picked facilitator.
						probeRequestId.current++;
					} }
				/>
				{ '' !== facilitator && (
					<>
						<HStack spacing={ 3 } justify="flex-start" className="simple-x402-page__probe-row">
							<Button
								variant="secondary"
								size="compact"
								type="button"
								icon={ boltIcon }
								iconSize={ 16 }
								onClick={ runTest }
								disabled={ testing }
								accessibleWhenDisabled
							>
								{ testing ? __( 'Running check…', 'simple-x402' ) : __( 'Test connection', 'simple-x402' ) }
							</Button>
							{ ( testing || probe ) && (
								<DiagnosticProbeLine
									pending={ testing && ! probe }
									success={ probe?.ok === true }
									durationMs={ probe?.ok ? probe.duration_ms : undefined }
									failureMessage={
										probe && ! probe.ok
											? ( probe.error || __( 'Unreachable', 'simple-x402' ) )
											: undefined
									}
								/>
							) }
						</HStack>
						<div className="simple-x402-page__divider" />
						<div
							className={
								walletError
									? 'simple-x402-page__wallet simple-x402-page__wallet--error'
									: 'simple-x402-page__wallet'
							}
						>
							<TextControl
								__nextHasNoMarginBottom
								__next40pxDefaultSize
								label={ __( 'Receiving wallet', 'simple-x402' ) }
								placeholder={ __( 'Add a valid EVM address 0x...', 'simple-x402' ) }
								help={ walletHelp }
								value={ walletValue }
								onChange={ ( value ) => onWalletChange( { wallet_address: value } ) }
								aria-invalid={ walletError ? 'true' : 'false' }
							/>
							{ walletError && (
								<p className="simple-x402-page__wallet-error" role="alert">
									{ walletError }
								</p>
							) }
						</div>
					</>
				) }
			</CardBody>
			<SaveFooter
				disabled={ ! isDirty || walletHasInvalidFormat }
				saving={ saving }
				error={ error }
				onSave={ onSave }
			/>
		</Card>
	);
}

function SettingsApp() {
	const [ saved, setSaved ] = useState( config.values );

	// Shared save engine. Fires one AJAX call, merges the server-canonical
	// response into `saved` so every card's isDirty check is evaluated against
	// whatever the sanitizer actually stored (which may differ from what we
	// sent, e.g. bad prices → 0.01). Returns `{ values, ajaxData }` so cards
	// can run follow-up checks (e.g. paywall probe) without a second request.
	const save = async ( partial ) => {
		const data = await saveFields( partial );
		let mergedValues;
		setSaved( ( prev ) => {
			mergedValues = { ...prev, ...data.values };
			return mergedValues;
		} );
		return { values: mergedValues, ajaxData: data };
	};

	const noticesRef = useRef( null );
	useEffect( () => {
		const slot = noticesRef.current;
		if ( ! slot ) return;

		const moveNotices = () => {
			const found = document.querySelectorAll(
				'#wpbody-content .notice, #wpbody-content .updated, #wpbody-content .error'
			);
			found.forEach( ( n ) => {
				if ( slot.contains( n ) ) return;
				// `.below-h2` exempts the notice from WP common.js's auto-relocate.
				n.classList.add( 'below-h2' );
				slot.appendChild( n );
			} );
		};

		moveNotices();
		const t = setTimeout( moveNotices, 50 );
		return () => clearTimeout( t );
	}, [] );

	return (
		<div className="simple-x402-page__content">
			<div className="simple-x402-page__notices" ref={ noticesRef } />
			<VStack spacing={ 6 }>
				<PaywallScopeCard saved={ saved } save={ save } />
				<AudienceCard saved={ saved } save={ save } />
				<PricingCard saved={ saved } save={ save } />
				<FacilitatorCard saved={ saved } save={ save } />
			</VStack>
		</div>
	);
}

const mount = document.getElementById( 'simple-x402-app' );
if ( mount ) {
	createRoot( mount ).render( <SettingsApp /> );
}
