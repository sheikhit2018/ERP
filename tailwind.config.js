import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.tsx',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', 'Hind Siliguri', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                brand: {
                    50: '#eefbf8', 100: '#d5f5ee', 200: '#aeeadd', 300: '#79d8c6', 400: '#43bfaa',
                    500: '#22a390', 600: '#168374', 700: '#15695f', 800: '#15544d', 900: '#144641', 950: '#06282a',
                },
                ink: { 900: '#0b1220', 800: '#111a2e', 700: '#1a2540' },
            },
        },
    },

    plugins: [forms],
};
