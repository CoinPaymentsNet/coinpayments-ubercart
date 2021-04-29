document.addEventListener('DOMContentLoaded', function () {
  CoinPayments.Button(
    {
      style: {color: "blue", width: 180},
      createInvoice: async function (data, actions) {
        const invoiceId = await actions.invoice.create(drupalSettings.uc_coinpayments.invoice);
        return invoiceId;
      },
    }
  ).render("coinpayments-checkout-button");
});
