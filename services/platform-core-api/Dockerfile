FROM mcr.microsoft.com/playwright:v1.61.1-noble
WORKDIR /app
COPY package.json ./
RUN npm install --omit=dev
COPY src ./src
ENV NODE_ENV=production
ENV PLAYWRIGHT_BROWSERS_PATH=/ms-playwright
RUN node src/check-browser.js
EXPOSE 3000
CMD ["npm","start"]
