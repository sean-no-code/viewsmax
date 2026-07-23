import type { Config } from "tailwindcss";

export default {
	darkMode: ["class"],
	content: [
		"./pages/**/*.{ts,tsx}",
		"./components/**/*.{ts,tsx}",
		"./app/**/*.{ts,tsx}",
		"./src/**/*.{ts,tsx}",
	],
	prefix: "",
	theme: {
		container: {
			center: true,
			padding: '2rem',
			screens: {
				'2xl': '1400px'
			}
		},
		extend: {
			fontFamily: {
				sans: ['Hanken Grotesk', 'system-ui', 'sans-serif'],
				body: ['Hanken Grotesk', 'system-ui', 'sans-serif'],
				display: ['Archivo', 'system-ui', 'sans-serif'],
				mono: ['JetBrains Mono', 'ui-monospace', 'monospace'],
			},
			colors: {
				vm: {
					red: 'var(--vm-red)',
					'red-hot': 'var(--vm-red-hot)',
					'red-deep': 'var(--vm-red-deep)',
					volt: 'var(--vm-volt)',
					'volt-deep': 'var(--vm-volt-deep)',
				},
				ink: {
					900: 'var(--ink-900)',
					800: 'var(--ink-800)',
					700: 'var(--ink-700)',
					'on-paper-1': 'var(--ink-on-paper-1)',
					'on-paper-2': 'var(--ink-on-paper-2)',
					'on-paper-3': 'var(--ink-on-paper-3)',
				},
				paper: {
					0: 'var(--paper-0)',
					1: 'var(--paper-1)',
					2: 'var(--paper-2)',
					3: 'var(--paper-3)',
				},
				line: {
					1: 'var(--line-1)',
					2: 'var(--line-2)',
				},
				data: {
					red: 'var(--data-red)',
					teal: 'var(--data-teal)',
					violet: 'var(--data-violet)',
					amber: 'var(--data-amber)',
					blue: 'var(--data-blue)',
					pink: 'var(--data-pink)',
					indigo: 'var(--data-indigo)',
				},
				up: 'var(--up)',
				down: 'var(--down)',
				border: 'hsl(var(--border))',
				input: 'hsl(var(--input))',
				ring: 'hsl(var(--ring))',
				background: 'hsl(var(--background))',
				foreground: 'hsl(var(--foreground))',
				primary: {
					DEFAULT: 'hsl(var(--primary))',
					foreground: 'hsl(var(--primary-foreground))'
				},
				secondary: {
					DEFAULT: 'hsl(var(--secondary))',
					foreground: 'hsl(var(--secondary-foreground))'
				},
				destructive: {
					DEFAULT: 'hsl(var(--destructive))',
					foreground: 'hsl(var(--destructive-foreground))'
				},
				muted: {
					DEFAULT: 'hsl(var(--muted))',
					foreground: 'hsl(var(--muted-foreground))'
				},
				accent: {
					DEFAULT: 'hsl(var(--accent))',
					foreground: 'hsl(var(--accent-foreground))'
				},
				popover: {
					DEFAULT: 'hsl(var(--popover))',
					foreground: 'hsl(var(--popover-foreground))'
				},
				card: {
					DEFAULT: 'hsl(var(--card))',
					foreground: 'hsl(var(--card-foreground))'
				},
				youtube: {
					red: 'hsl(var(--youtube-red))',
					'red-light': 'hsl(var(--youtube-red-light))',
					'red-dark': 'hsl(var(--youtube-red-dark))'
				},
				sidebar: {
					DEFAULT: 'hsl(var(--sidebar-background))',
					foreground: 'hsl(var(--sidebar-foreground))',
					primary: 'hsl(var(--sidebar-primary))',
					'primary-foreground': 'hsl(var(--sidebar-primary-foreground))',
					accent: 'hsl(var(--sidebar-accent))',
					'accent-foreground': 'hsl(var(--sidebar-accent-foreground))',
					border: 'hsl(var(--sidebar-border))',
					ring: 'hsl(var(--sidebar-ring))'
				}
			},
			borderRadius: {
				lg: 'var(--radius)',
				md: 'calc(var(--radius) - 2px)',
				sm: 'calc(var(--radius) - 4px)'
			},
			keyframes: {
				'accordion-down': {
					from: {
						height: '0'
					},
					to: {
						height: 'var(--radix-accordion-content-height)'
					}
				},
				'accordion-up': {
					from: {
						height: 'var(--radix-accordion-content-height)'
					},
					to: {
						height: '0'
					}
				},
				'fade-in': {
					'0%': { opacity: '0', transform: 'translateY(20px)' },
					'100%': { opacity: '1', transform: 'translateY(0)' }
				},
				'float': {
					'0%, 100%': { transform: 'translateY(0)' },
					'50%': { transform: 'translateY(-10px)' }
				}
			},
			animation: {
				'accordion-down': 'accordion-down 0.2s ease-out',
				'accordion-up': 'accordion-up 0.2s ease-out',
				'fade-in': 'fade-in 0.6s ease-out',
				'float': 'float 6s ease-in-out infinite'
			},
			backgroundImage: {
				'gradient-primary': 'var(--gradient-primary)',
				'gradient-secondary': 'var(--gradient-secondary)',
				'gradient-hero': 'var(--gradient-hero)'
			},
			boxShadow: {
				'primary': 'var(--shadow-primary)',
				'card': 'var(--shadow-card)',
				'hero': 'var(--shadow-hero)',
				'hard': 'var(--hard)',
				'hard-red': 'var(--hard-red)'
			}
		}
	},
	plugins: [require("tailwindcss-animate")],
} satisfies Config;
