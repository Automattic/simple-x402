import { createRoot, useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardBody,
	CardFooter,
	CardHeader,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
	__experimentalText as Text,
} from '@wordpress/components';
import { DataForm } from '@wordpress/dataviews';

import './style.scss';

const config = window.simpleX402Settings;

function name( field ) {
	return `${ config.option }[${ field }]`;
}

function nestedName( mode, field ) {
	return `${ config.option }[${ mode }][${ field }]`;
}

function SaveFooter( { disabled = false } ) {
	return (
		<CardFooter className="simple-x402-page__card-footer">
			<HStack justify="flex-start">
				<Button variant="primary" type="submit" disabled={ disabled } accessibleWhenDisabled>
					{ __( 'Save changes', 'simple-x402' ) }
				</Button>
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

function PaywallScopeCard( { paywallMode, setPaywallMode, termId, setTermId } ) {
	const data = { paywallMode, termId: String( termId ) };
	const onChange = ( edits ) => {
		if ( 'paywallMode' in edits ) setPaywallMode( edits.paywallMode );
		if ( 'termId' in edits ) setTermId( Number( edits.termId ) );
	};
	const isDirty =
		paywallMode !== config.values.paywall_mode ||
		Number( termId ) !== Number( config.values.paywall_category_term_id );
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
					data={ data }
					fields={ PAYWALL_MODE_FIELDS }
					form={ {
						layout: { type: 'regular', labelPosition: 'none' },
						fields: [
							'paywallMode',
							{
								id: 'termId',
								layout: { type: 'regular', labelPosition: 'top' },
							},
						],
					} }
					onChange={ onChange }
				/>
				<input type="hidden" name={ name( 'paywall_mode' ) } value={ paywallMode } />
				<input
					type="hidden"
					name={ name( 'paywall_category_term_id' ) }
					value={ termId }
				/>
			</CardBody>
			<SaveFooter disabled={ ! isDirty } />
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

function AudienceCard( { audience, setAudience } ) {
	const isDirty = audience !== config.values.paywall_audience;
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
				<input type="hidden" name={ name( 'paywall_audience' ) } value={ audience } />
			</CardBody>
			<SaveFooter disabled={ ! isDirty } />
		</Card>
	);
}

const MODE_FIELDS = [
	{
		id: 'isLive',
		label: __( 'Enable live mode', 'simple-x402' ),
		type: 'boolean',
		Edit: 'toggle',
	},
];

const TEST_FIELDS = [
	{
		id: 'wallet_address',
		label: __( 'Receiving wallet (Base Sepolia)', 'simple-x402' ),
		type: 'text',
	},
	{
		id: 'default_price',
		label: __( 'Price per request (USDC)', 'simple-x402' ),
		type: 'number',
	},
];

const LIVE_FIELDS = [
	{
		id: 'wallet_address',
		label: __( 'Receiving wallet (Base mainnet)', 'simple-x402' ),
		type: 'text',
	},
	{
		id: 'default_price',
		label: __( 'Price per request (USDC)', 'simple-x402' ),
		type: 'number',
	},
	{
		id: 'facilitator_url',
		label: __( 'Facilitator URL', 'simple-x402' ),
		type: 'text',
		placeholder: config.liveFacilitatorPlaceholder,
		description: __( 'Leave blank to use the Coinbase CDP default.', 'simple-x402' ),
	},
	{
		id: 'facilitator_api_key',
		label: __( 'Facilitator API key', 'simple-x402' ),
		type: 'password',
	},
];

function ActiveBadge() {
	return (
		<span className="simple-x402-badge simple-x402-badge--active">
			{ __( 'Active', 'simple-x402' ) }
		</span>
	);
}

const DEFAULT_FACILITATOR_VALUE = '';

function facilitatorOptions() {
	const entries = ( config.facilitators || [] ).map( ( f ) => ( {
		value: f.id,
		label: f.name || f.id,
	} ) );
	return [
		{
			value: DEFAULT_FACILITATOR_VALUE,
			label: __( 'Default (use the mode-based settings below)', 'simple-x402' ),
		},
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

function FacilitatorCard( { facilitator, setFacilitator } ) {
	const [ probe, setProbe ] = useState( null ); // { ok, http_code, duration_ms, error } | null
	const [ testing, setTesting ] = useState( false );
	const isDirty = facilitator !== ( config.values.selected_facilitator_id || '' );
	const testable = Boolean( config.testConnection?.url ) && '' !== facilitator;

	const runTest = async () => {
		setTesting( true );
		setProbe( null );
		try {
			const body = new FormData();
			body.append( 'action', config.testConnection.action );
			body.append( 'nonce', config.testConnection.nonce );
			body.append( 'connector_id', facilitator );
			const resp = await fetch( config.testConnection.url, {
				method: 'POST',
				credentials: 'same-origin',
				body,
			} );
			const json = await resp.json();
			setProbe( json.success ? json.data : { ok: false, error: json.data?.error || 'request_failed' } );
		} catch ( e ) {
			setProbe( { ok: false, error: String( e ) } );
		} finally {
			setTesting( false );
		}
	};

	return (
		<Card>
			<CardHeader>
				<CardTitle
					title={ __( 'Facilitator', 'simple-x402' ) }
					subtitle={ __(
						'Where verify and settle requests are sent. Leave on Default to keep the legacy mode-based path.',
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
					} }
				/>
				<input type="hidden" name={ name( 'selected_facilitator_id' ) } value={ facilitator || '' } />
				<HStack spacing={ 2 } justify="flex-start" style={ { marginTop: 12 } }>
					<Button
						variant="secondary"
						onClick={ runTest }
						disabled={ ! testable || testing }
						accessibleWhenDisabled
					>
						{ testing
							? __( 'Testing…', 'simple-x402' )
							: __( 'Test connection', 'simple-x402' ) }
					</Button>
					{ probe && (
						<Text size={ 13 } variant={ probe.ok ? 'muted' : 'muted' }>
							{ probe.ok
								? `✓ ${ probe.http_code ?? '' } in ${ probe.duration_ms ?? '?' }ms`
								: `✗ ${ probe.error || __( 'Unreachable', 'simple-x402' ) }` }
						</Text>
					) }
				</HStack>
			</CardBody>
			<SaveFooter disabled={ ! isDirty } />
		</Card>
	);
}

function PaymentDetailsCard( { mode, setMode } ) {
	const isLive = mode === config.modes.facilitator.live;
	const isDirty = mode !== config.values.mode;

	return (
		<Card>
			<CardHeader>
				<CardTitle
					title={ __( 'Mode', 'simple-x402' ) }
					subtitle={ __(
						'Switch between the test network and live payments.',
						'simple-x402'
					) }
				/>
			</CardHeader>
			<CardBody>
				<DataForm
					data={ { isLive } }
					fields={ MODE_FIELDS }
					form={ {
						layout: { type: 'regular', labelPosition: 'none' },
						fields: [ 'isLive' ],
					} }
					onChange={ ( edits ) =>
						setMode(
							edits.isLive
								? config.modes.facilitator.live
								: config.modes.facilitator.test
						)
					}
				/>
				<input type="hidden" name={ name( 'mode' ) } value={ mode } />
			</CardBody>
			<SaveFooter disabled={ ! isDirty } />
		</Card>
	);
}

function PaymentSettingsCard( { testValues, setTest, liveValues, setLive } ) {
	const savedIsLive = config.values.mode === config.modes.facilitator.live;
	const isDirty =
		! isShallowEqual( testValues, config.values.test ) ||
		! isShallowEqual( liveValues, config.values.live );

	return (
		<Card>
			<CardHeader>
				<CardTitle
					title={ __( 'Payment settings', 'simple-x402' ) }
					subtitle={ __(
						'Wallet address and pricing for each network.',
						'simple-x402'
					) }
				/>
			</CardHeader>
			<CardBody>
				<VStack spacing={ 6 }>
					<VStack spacing={ 3 }>
						<HStack spacing={ 2 } justify="flex-start">
							<Text size={ 13 } weight={ 600 }>
								{ __( 'Test settings', 'simple-x402' ) }
							</Text>
							{ ! savedIsLive && <ActiveBadge /> }
						</HStack>
						<DataForm
							data={ testValues }
							fields={ TEST_FIELDS }
							form={ {
								layout: { type: 'regular', labelPosition: 'top' },
								fields: TEST_FIELDS.map( ( f ) => f.id ),
							} }
							onChange={ ( edits ) => setTest( { ...testValues, ...edits } ) }
						/>
						<input type="hidden" name={ nestedName( 'test', 'wallet_address' ) } value={ testValues.wallet_address || '' } />
						<input type="hidden" name={ nestedName( 'test', 'default_price' ) } value={ testValues.default_price || '' } />
					</VStack>

					<VStack spacing={ 3 }>
						<HStack spacing={ 2 } justify="flex-start">
							<Text size={ 13 } weight={ 600 }>
								{ __( 'Live settings', 'simple-x402' ) }
							</Text>
							{ savedIsLive && <ActiveBadge /> }
						</HStack>
						<DataForm
							data={ liveValues }
							fields={ LIVE_FIELDS }
							form={ {
								layout: { type: 'regular', labelPosition: 'top' },
								fields: LIVE_FIELDS.map( ( f ) => f.id ),
							} }
							onChange={ ( edits ) => setLive( { ...liveValues, ...edits } ) }
						/>
						<input type="hidden" name={ nestedName( 'live', 'wallet_address' ) } value={ liveValues.wallet_address || '' } />
						<input type="hidden" name={ nestedName( 'live', 'default_price' ) } value={ liveValues.default_price || '' } />
						<input type="hidden" name={ nestedName( 'live', 'facilitator_url' ) } value={ liveValues.facilitator_url || '' } />
						<input type="hidden" name={ nestedName( 'live', 'facilitator_api_key' ) } value={ liveValues.facilitator_api_key || '' } />
					</VStack>
				</VStack>
			</CardBody>
			<SaveFooter disabled={ ! isDirty } />
		</Card>
	);
}

function SettingsApp() {
	const initial = config.values;

	const [ mode, setMode ] = useState( initial.mode );
	const [ paywallMode, setPaywallMode ] = useState( initial.paywall_mode );
	const [ audience, setAudience ] = useState( initial.paywall_audience );
	const [ termId, setTermId ] = useState( initial.paywall_category_term_id );
	const [ testValues, setTest ] = useState( initial.test );
	const [ liveValues, setLive ] = useState( initial.live );
	const [ facilitator, setFacilitator ] = useState( initial.selected_facilitator_id || '' );

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
				<PaywallScopeCard
					paywallMode={ paywallMode }
					setPaywallMode={ setPaywallMode }
					termId={ termId }
					setTermId={ setTermId }
				/>

				<AudienceCard audience={ audience } setAudience={ setAudience } />

				<FacilitatorCard
					facilitator={ facilitator }
					setFacilitator={ setFacilitator }
				/>

				<PaymentDetailsCard mode={ mode } setMode={ setMode } />

				<PaymentSettingsCard
					testValues={ testValues }
					setTest={ setTest }
					liveValues={ liveValues }
					setLive={ setLive }
				/>
			</VStack>
		</div>
	);
}

const mount = document.getElementById( 'simple-x402-app' );
if ( mount ) {
	createRoot( mount ).render( <SettingsApp /> );
}
