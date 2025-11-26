/**
 * Retrieves the translation of text.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/packages/packages-i18n/
 */
import { __ } from '@wordpress/i18n';

/**
 * React hook that is used to mark the block wrapper element.
 * It provides all the necessary props like the class name.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/packages/packages-block-editor/#useblockprops
 */
import {
	useBlockProps,
	InspectorControls,
	PanelColorSettings,
	RichText,
} from '@wordpress/block-editor';
import { PanelBody, TextControl } from '@wordpress/components';

/**
 * Lets webpack process CSS, SASS or SCSS files referenced in JavaScript files.
 * Those files can contain any CSS code that gets applied to the editor.
 *
 * @see https://www.npmjs.com/package/@wordpress/scripts#using-css
 */
import './editor.scss';

/**
 * The edit function describes the structure of your block in the context of the
 * editor. This represents what the editor will render when the block is used.
 *
 * @param {Object}   root0               - The root object.
 * @param {Object}   root0.attributes    - Block attributes.
 * @param {Function} root0.setAttributes - Attribute setter.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/block-api/block-edit-save/#edit
 *
 * @return {Element} Element to render.
 */
export default function Edit( { attributes, setAttributes } ) {
	const blockProps = useBlockProps( { className: 'batp-itinerary-block' } );
	const { heading, subheading, ctaLabel, highlightColor } = attributes;

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __( 'Content', 'brooklyn-ai-planner' ) }
					initialOpen={ true }
				>
					<TextControl
						label={ __( 'Heading', 'brooklyn-ai-planner' ) }
						value={ heading }
						onChange={ ( value ) =>
							setAttributes( { heading: value } )
						}
					/>
					<TextControl
						label={ __( 'Subheading', 'brooklyn-ai-planner' ) }
						value={ subheading }
						onChange={ ( value ) =>
							setAttributes( { subheading: value } )
						}
					/>
					<TextControl
						label={ __( 'CTA label', 'brooklyn-ai-planner' ) }
						value={ ctaLabel }
						onChange={ ( value ) =>
							setAttributes( { ctaLabel: value } )
						}
					/>
				</PanelBody>
				<PanelColorSettings
					title={ __( 'Highlight color', 'brooklyn-ai-planner' ) }
					colorSettings={ [
						{
							value: highlightColor,
							onChange: ( value ) =>
								setAttributes( { highlightColor: value } ),
							label: __( 'Accent', 'brooklyn-ai-planner' ),
						},
					] }
				/>
			</InspectorControls>
			<div
				{ ...blockProps }
				style={ { '--batp-highlight-color': highlightColor } }
			>
				<RichText
					tagName="h2"
					className="batp-itinerary-block__heading"
					value={ heading }
					onChange={ ( value ) =>
						setAttributes( { heading: value } )
					}
					placeholder={ __(
						'Plan your perfect Brooklyn day',
						'brooklyn-ai-planner'
					) }
				/>
				<RichText
					tagName="p"
					className="batp-itinerary-block__subheading"
					value={ subheading }
					onChange={ ( value ) =>
						setAttributes( { subheading: value } )
					}
					placeholder={ __(
						'Tell us what you love…',
						'brooklyn-ai-planner'
					) }
				/>
				<button
					type="button"
					className="batp-itinerary-block__cta"
					style={ { backgroundColor: highlightColor } }
				>
					{ ctaLabel }
				</button>
			</div>
		</>
	);
}
