// Baileys 7 e' solo ESM: dentro l'exe (Node SEA) import() non funziona dallo script incorporato,
// ma funziona da un file vero su disco. Il Bridge carica questo file con require e chiama la funzione.
module.exports = () => import('@whiskeysockets/baileys');
