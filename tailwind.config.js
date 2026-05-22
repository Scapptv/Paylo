/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './resources/**/*.blade.php',
        './resources/**/*.js',
        './resources/**/*.vue',
    ],
    theme: {
        extend: {
            colors: {
                // Paylo dark palette (eyni HTML mock-larındakı CSS variables)
                bg:        '#0a0b0f',
                'bg-2':    '#0f1117',
                surface:   '#14161e',
                'surface-2':'#1b1e29',
                'surface-3':'#232735',
                border:    '#242838',
                'border-2':'#2f3445',
                text:      '#e8eaf0',
                'text-2':  '#c5c9d6',
                muted:     '#7a8094',
                accent:    '#c8ff3d',
                'accent-orange':'#ff7a4d',
                'accent-blue':  '#6c8eef',
                'accent-purple':'#b794f6',
                danger:    '#ff5470',
                success:   '#58e1a3',
                warning:   '#ffc857',
            },
            fontFamily: {
                sans:    ['Manrope', 'system-ui', 'sans-serif'],
                serif:   ['Fraunces', 'serif'],
                mono:    ['JetBrains Mono', 'monospace'],
                display: ['Fraunces', 'serif'],
            },
            letterSpacing: {
                widest: '0.2em',
                wider:  '0.16em',
            },
            animation: {
                'rise': 'rise 0.7s cubic-bezier(0.2,0.8,0.2,1) backwards',
            },
            keyframes: {
                rise: {
                    'from': { opacity: '0', transform: 'translateY(24px)' },
                    'to':   { opacity: '1', transform: 'translateY(0)' },
                },
            },
        },
    },
    plugins: [require('@tailwindcss/forms')],
};
