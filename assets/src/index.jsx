import { createRoot, useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
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

function facilitatorField( facilitatorId, field ) {
	return `${ config.option }[facilitators][${ facilitatorId }][${ field }]`;
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

function facilitatorOptions() {
	const entries = ( config.facilitators || [] ).map( ( f ) => ( {
		value: f.id,
		label: f.name || f.id,
	} ) );
	return [
		{
			value: '',
			label: __( '— Select a facilitator —', 'simple-x402' ),
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

const WALLET_FIELDS = [
	{
		id: 'wallet_address',
		label: __( 'Receiving wallet', 'simple-x402' ),
		type: 'text',
	},
];

const PRICING_FIELDS = [
	{
		id: 'default_price',
		label: __( 'Price per request (USDC)', 'simple-x402' ),
		type: 'number',
	},
];

const emptySlot = () => ( { wallet_address: '' } );

function PricingCard( { price, setPrice } ) {
	const isDirty = String( price ?? '' ) !== String( config.values.default_price ?? '' );
	return (
		<Card>
			<CardHeader>
				<CardTitle
					title={ __( 'Pricing', 'simple-x402' ) }
					subtitle={ __(
						'How much each paywalled request costs, in USDC. Charged the same whether you’re settling on testnet or mainnet.',
						'simple-x402'
					) }
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
				<input type="hidden" name={ name( 'default_price' ) } value={ price || '' } />
			</CardBody>
			<SaveFooter disabled={ ! isDirty } />
		</Card>
	);
}

function FacilitatorCard( { facilitator, setFacilitator, slots, setSlots } ) {
	const [ probe, setProbe ] = useState( null );
	const [ testing, setTesting ] = useState( false );

	const slot   = '' === facilitator ? emptySlot() : ( slots[ facilitator ] ?? emptySlot() );
	const saved  = '' === facilitator
		? emptySlot()
		: ( ( config.values.facilitators || {} )[ facilitator ] ?? emptySlot() );
	const savedId = config.values.selected_facilitator_id || '';

	const isDirty =
		facilitator !== savedId ||
		( '' !== facilitator && ! isShallowEqual( slot, saved ) );

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

	const onPaymentChange = ( edits ) => {
		setSlots( {
			...slots,
			[ facilitator ]: { ...slot, ...edits },
		} );
	};

	return (
		<Card>
			<CardHeader>
				<CardTitle
					title={ __( 'Facilitator', 'simple-x402' ) }
					subtitle={ __(
						'Where verify and settle requests are sent, and where payments land. The paywall is inert until one is selected.',
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
				{ '' !== facilitator && (
					<>
						<HStack spacing={ 3 } justify="flex-start" className="simple-x402-page__probe-row">
							<Button
								variant="link"
								type="button"
								onClick={ runTest }
								disabled={ testing }
								accessibleWhenDisabled
							>
								{ testing ? __( 'Testing…', 'simple-x402' ) : __( 'Test connection', 'simple-x402' ) }
							</Button>
							{ probe && (
								<Text size={ 13 } variant="muted">
									{ probe.ok
										? sprintf(
											/* translators: %d: probe duration in milliseconds. */
											__( '✓ Succeeded in %dms', 'simple-x402' ),
											probe.duration_ms ?? 0
										)
										: `✗ ${ probe.error || __( 'Unreachable', 'simple-x402' ) }` }
								</Text>
							) }
						</HStack>
						<div className="simple-x402-page__divider" />
						<DataForm
							data={ slot }
							fields={ WALLET_FIELDS }
							form={ {
								layout: { type: 'regular', labelPosition: 'top' },
								fields: WALLET_FIELDS.map( ( f ) => f.id ),
							} }
							onChange={ onPaymentChange }
						/>
					</>
				) }
				{ /* Submit every known slot so unrelated facilitators' stored values aren't wiped on save. */ }
				{ Object.entries( slots ).map( ( [ id, entry ] ) => (
					<input
						key={ id }
						type="hidden"
						name={ facilitatorField( id, 'wallet_address' ) }
						value={ entry.wallet_address || '' }
					/>
				) ) }
			</CardBody>
			<SaveFooter disabled={ ! isDirty } />
		</Card>
	);
}

function SettingsApp() {
	const initial = config.values;

	const [ paywallMode, setPaywallMode ] = useState( initial.paywall_mode );
	const [ audience, setAudience ] = useState( initial.paywall_audience );
	const [ termId, setTermId ] = useState( initial.paywall_category_term_id );
	const [ facilitator, setFacilitator ] = useState( initial.selected_facilitator_id || '' );
	const [ slots, setSlots ] = useState( initial.facilitators || {} );
	const [ price, setPrice ] = useState( initial.default_price || '' );

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

				<PricingCard price={ price } setPrice={ setPrice } />

				<FacilitatorCard
					facilitator={ facilitator }
					setFacilitator={ setFacilitator }
					slots={ slots }
					setSlots={ setSlots }
				/>
			</VStack>
		</div>
	);
}

const mount = document.getElementById( 'simple-x402-app' );
if ( mount ) {
	createRoot( mount ).render( <SettingsApp /> );
}
