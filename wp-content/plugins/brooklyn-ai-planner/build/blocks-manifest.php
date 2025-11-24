<?php
// This file is generated. Do not modify it manually.
return array(
	'brooklyn-ai-planner' => array(
		'$schema' => 'https://schemas.wp.org/trunk/block.json',
		'apiVersion' => 3,
		'name' => 'brooklyn-ai/itinerary-request',
		'version' => '0.2.0',
		'title' => 'Brooklyn AI Itinerary Request',
		'category' => 'widgets',
		'icon' => 'admin-site-alt3',
		'description' => 'Collect preferred vibes, dates, and party size for the Brooklyn AI trip planner.',
		'keywords' => array(
			'brooklyn',
			'ai',
			'travel'
		),
		'supports' => array(
			'html' => false,
			'align' => array(
				'wide',
				'full'
			)
		),
		'attributes' => array(
			'heading' => array(
				'type' => 'string',
				'default' => 'Plan your perfect Brooklyn day'
			),
			'subheading' => array(
				'type' => 'string',
				'default' => 'Tell us what you love and we will craft a Gemini-powered itinerary.'
			),
			'ctaLabel' => array(
				'type' => 'string',
				'default' => 'Generate itinerary'
			),
			'highlightColor' => array(
				'type' => 'string',
				'default' => '#ff4f5e'
			)
		),
		'textdomain' => 'brooklyn-ai-planner',
		'editorScript' => 'file:./index.js',
		'editorStyle' => 'file:./index.css',
		'style' => 'file:./style-index.css',
		'render' => 'file:./render.php',
		'viewScript' => 'file:./view.js'
	)
);
