FROM node:20-alpine

WORKDIR /app

# Instalar dependencias necesarias para compilar o correr módulos nativos si se requieren
RUN apk add --no-cache git

COPY package*.json ./
RUN npm install --omit=dev

COPY . .

# Exponer el puerto del WhatsApp Gateway
EXPOSE 8088

CMD ["node", "server.js"]
