/** @type {import('tailwindcss').Config} */
module.exports = {
    content: [
        './templates/**/*.php',
        './modules/**/*.php',
        './index.php',
        './list.php',
        './stats.php',
    ],
    theme: {
        extend: {
            colors: {
                'evo-dark': '#040214',
                'evo-blue': '#0060ff',
                'evo-navy': '#0f172a'
            },
            fontFamily: {
                'prodsans': ['Open Sans', 'sans-serif'],
                'prodsansbold': ['Open Sans', 'sans-serif']
            }
        }
    },
    plugins: [],
}
