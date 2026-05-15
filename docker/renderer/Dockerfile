FROM mcr.microsoft.com/playwright:v1.48.0-noble

WORKDIR /usr/src/app

COPY docker/renderer/package.json ./
COPY docker/renderer/server.js ./

RUN npm install --omit=dev

EXPOSE 3000

CMD ["node", "server.js"]
