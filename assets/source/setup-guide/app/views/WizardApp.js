/**
 * External dependencies
 */
import '@wordpress/notices';
import { __ } from '@wordpress/i18n';
import { createElement, useState } from '@wordpress/element';
import { Spinner, Stepper } from '@woocommerce/components';
import {
	getHistory,
	getQuery,
	updateQueryString,
} from '@woocommerce/navigation';

/**
 * Internal dependencies
 */
import SetupAccount from '../steps/SetupAccount';
import VerifyDomain from '../steps/VerifyDomain';
import ConfigureSettings from '../steps/ConfigureSettings';
import TransientNotices from '../components/TransientNotices';
import { useBodyClasses, useCreateNotice } from '../helpers/effects';

const WizardApp = () => {
	const [ currentStep, setCurrentStep ] = useState();

	useBodyClasses( 'wizard' );
	useCreateNotice( wcSettings.pin4wc.error );

	const steps = [
		{
			key: 'setup-account',
			container: SetupAccount,
			label: __( 'Set up your account', 'pinterest-for-woocommerce' ),
		},
		{
			key: 'verify-domain',
			container: VerifyDomain,
			label: __( 'Verify your domain', 'pinterest-for-woocommerce' ),
		},
		{
			key: 'configure-settings',
			container: ConfigureSettings,
			label: __( 'Configure your settings', 'pinterest-for-woocommerce' ),
		},
	];

	const getSteps = () => {
		return steps.map( ( step, index ) => {
			const container = createElement( step.container, {
				query: getQuery(),
				step,
				goToNextStep: () => goToNextStep( step ),
				view: 'wizard',
			} );

			step.content = (
				<div
					className={ `woocommerce-setup-guide__container ${ step.key }` }
				>
					{ container }
				</div>
			);

			const previousStep = steps[ index - 1 ];

			if ( ! previousStep || previousStep.isComplete ) {
				step.onClick = ( key ) => updateQueryString( { step: key } );
			}

			return step;
		} );
	};

	const getCurrentStep = () => {
		const query = getQuery();
		const step = steps.find( ( s ) => s.key === query.step );

		if ( ! step ) {
			return steps[ 0 ].key;
		}

		return step.key;
	};

	const goToNextStep = ( step ) => {
		const currentStepIndex = steps.findIndex( ( s ) => s.key === step.key );

		const nextStep = steps[ currentStepIndex + 1 ];

		if ( typeof nextStep === 'undefined' ) {
			return;
		}

		return updateQueryString( { step: nextStep.key } );
	};

	getHistory().listen( () => {
		setCurrentStep( getCurrentStep() );
	} );

	if ( ! currentStep ) {
		setCurrentStep( getCurrentStep() );

		return <Spinner />;
	}

	return (
		<div className="woocommerce-layout">
			<div className="woocommerce-layout__main woocommerce-setup-guide__main">
				<TransientNotices />
				<Stepper currentStep={ currentStep } steps={ getSteps() } />
			</div>
		</div>
	);
};

export default WizardApp;