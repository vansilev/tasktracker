import defaultTheme from 'tailwindcss/defaultTheme';
import colors from 'tailwindcss/colors';
import forms from '@tailwindcss/forms';
import typography from '@tailwindcss/typography';

/*
 * Design tokens (docs/design-audit.md, section 5).
 * Colours: one neutral scale (gray), primary = AVANT navy, accent = AVANT gold,
 * and four semantic families. Use the semantic names in new code.
 */
const primary = {
    50: '#EEF2FA',
    100: '#DCE4F4',
    200: '#B9C9E8',
    300: '#8EA6D6',
    400: '#5F7FC0',
    500: '#3A5BA8',
    600: '#1F3A8A',
    700: '#172E6E',
    800: '#122557',
    900: '#14213D',
    950: '#0B1426',
};

const accent = {
    50: '#FBF6E6',
    100: '#F5EAC2',
    200: '#EBD58A',
    300: '#DFBD52',
    400: '#D4AD36',
    500: '#C9A227',
    600: '#A8841A',
    700: '#85680F',
    800: '#634D0B',
    900: '#423307',
};

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.js',
        './app/Enums/**/*.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['"Inter Variable"', 'Inter', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                primary,
                brand: primary,
                accent,
                success: colors.green,
                warning: colors.amber,
                danger: colors.red,
                info: colors.blue,
            },
            // Type scale: caption 12 · body-sm 13 · body 14 · title-sm 16 · title 20 · display 24.
            fontSize: {
                caption: ['0.75rem', { lineHeight: '1rem' }],
                'body-sm': ['0.8125rem', { lineHeight: '1.25rem' }],
                body: ['0.875rem', { lineHeight: '1.25rem' }],
                'title-sm': ['1rem', { lineHeight: '1.5rem' }],
                title: ['1.25rem', { lineHeight: '1.75rem' }],
                display: ['1.5rem', { lineHeight: '2rem' }],
            },
            // Three radii: 6px for controls and badges, 10px for surfaces, full.
            borderRadius: {
                DEFAULT: '6px',
                sm: '6px',
                md: '6px',
                lg: '6px',
                xl: '10px',
                '2xl': '10px',
                '3xl': '10px',
                control: '6px',
                surface: '10px',
            },
            // Surfaces are flat (border only); only floating layers cast a shadow.
            boxShadow: {
                sm: '0 0 #0000',
                DEFAULT: '0 0 #0000',
                md: '0 8px 24px rgb(15 23 42 / 0.12)',
                lg: '0 8px 24px rgb(15 23 42 / 0.12)',
                xl: '0 12px 32px rgb(15 23 42 / 0.16)',
                '2xl': '0 12px 32px rgb(15 23 42 / 0.16)',
                overlay: '0 8px 24px rgb(15 23 42 / 0.12)',
            },
        },
    },

    plugins: [forms, typography],
};
