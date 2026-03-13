module.exports = {
  apps: [
    {
      name: 'XMPlus',
      script: '.output/server/index.mjs',
      instances: 'max',
      exec_mode: 'cluster',
      watch: false,
      env: {
        API_URL: 'https://api.tld.com',
		PORT: 3005,
        DEBUG: false,
		SESSION_HTTPONLY: true,
		SESSION_SECURE: true,
		SESSION_SAME_SITE: 'lax'
      }
    }
  ]
}
