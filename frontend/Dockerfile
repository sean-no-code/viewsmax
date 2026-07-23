# Development image for the Vite dev server. The production build is a static
# bundle (npm run build -> dist/), deployed however you like — see
# .github/workflows/deploy.yaml for one example.
FROM node:24-bookworm-slim

WORKDIR /app

# Install dependencies in their own layer so code edits don't reinstall them.
COPY package.json package-lock.json ./
RUN npm ci

COPY . .

EXPOSE 8080

# --host makes the dev server reachable from outside the container.
CMD ["npm", "run", "dev", "--", "--host", "0.0.0.0"]
