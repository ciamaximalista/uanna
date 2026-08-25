package com.ciamaximalista.uanna;

import android.Manifest;
import android.app.Activity;
import android.app.DownloadManager;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.content.Context;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.graphics.Bitmap;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.os.Environment;
import android.view.View;
import android.view.Window;
import android.webkit.CookieManager;
import android.webkit.URLUtil;
import android.webkit.ValueCallback;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.ProgressBar;
import android.widget.Toast;

public final class MainActivity extends Activity {
    private static final String CHANNEL_ID = "uanna_notifications";
    private static final int FILE_CHOOSER_REQUEST = 1001;
    private static final int NOTIFICATION_PERMISSION_REQUEST = 1002;
    private static final long BADGE_POLL_MS = 30000L;

    private WebView webView;
    private ProgressBar progress;
    private ValueCallback<Uri[]> filePathCallback;
    private final Handler handler = new Handler(Looper.getMainLooper());
    private int lastBadgeCount = -1;

    private final Runnable badgePoller = new Runnable() {
        @Override
        public void run() {
            pollBadgeCount();
            handler.postDelayed(this, BADGE_POLL_MS);
        }
    };

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        requestWindowFeature(Window.FEATURE_NO_TITLE);
        createNotificationChannel();
        requestNotificationPermission();

        progress = new ProgressBar(this, null, android.R.attr.progressBarStyleHorizontal);
        progress.setMax(100);

        webView = new WebView(this);
        configureWebView();

        setContentView(webView);
        webView.loadUrl(getString(R.string.site_url));
    }

    @Override
    protected void onResume() {
        super.onResume();
        handler.postDelayed(badgePoller, 3000L);
    }

    @Override
    protected void onPause() {
        handler.removeCallbacks(badgePoller);
        super.onPause();
    }

    @Override
    protected void onDestroy() {
        handler.removeCallbacks(badgePoller);
        if (webView != null) {
            webView.destroy();
        }
        super.onDestroy();
    }

    @Override
    public void onBackPressed() {
        if (webView != null && webView.canGoBack()) {
            webView.goBack();
            return;
        }
        super.onBackPressed();
    }

    @Override
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        super.onActivityResult(requestCode, resultCode, data);
        if (requestCode != FILE_CHOOSER_REQUEST || filePathCallback == null) {
            return;
        }

        Uri[] results = null;
        if (resultCode == RESULT_OK && data != null) {
            Uri uri = data.getData();
            if (uri != null) {
                results = new Uri[]{uri};
            }
        }

        filePathCallback.onReceiveValue(results);
        filePathCallback = null;
    }

    private void configureWebView() {
        CookieManager.getInstance().setAcceptCookie(true);
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP) {
            CookieManager.getInstance().setAcceptThirdPartyCookies(webView, true);
        }

        WebSettings settings = webView.getSettings();
        settings.setJavaScriptEnabled(true);
        settings.setDomStorageEnabled(true);
        settings.setDatabaseEnabled(true);
        settings.setAllowFileAccess(true);
        settings.setAllowContentAccess(true);
        settings.setMediaPlaybackRequiresUserGesture(false);
        settings.setLoadWithOverviewMode(true);
        settings.setUseWideViewPort(true);

        webView.setWebViewClient(new UannaWebViewClient());
        webView.setWebChromeClient(new UannaWebChromeClient());
        webView.setDownloadListener(this::downloadFile);
    }

    private void downloadFile(String url, String userAgent, String contentDisposition, String mimeType, long contentLength) {
        if (url == null || url.trim().isEmpty()) {
            return;
        }

        Uri uri = Uri.parse(url);
        String scheme = uri.getScheme();
        if (!"http".equalsIgnoreCase(scheme) && !"https".equalsIgnoreCase(scheme)) {
            startActivity(new Intent(Intent.ACTION_VIEW, uri));
            return;
        }

        String filename = URLUtil.guessFileName(url, contentDisposition, mimeType);
        DownloadManager.Request request = downloadRequest(uri, filename, mimeType, cookieFor(url), userAgent, true);

        DownloadManager manager = (DownloadManager) getSystemService(Context.DOWNLOAD_SERVICE);
        if (manager == null) {
            startActivity(new Intent(Intent.ACTION_VIEW, uri));
            return;
        }

        try {
            manager.enqueue(request);
        } catch (SecurityException | IllegalArgumentException e) {
            try {
                manager.enqueue(downloadRequest(uri, filename, mimeType, cookieFor(url), userAgent, false));
            } catch (SecurityException | IllegalArgumentException ignored) {
                startActivity(new Intent(Intent.ACTION_VIEW, uri));
                return;
            }
        }
        Toast.makeText(this, "Descarga iniciada", Toast.LENGTH_SHORT).show();
    }

    private DownloadManager.Request downloadRequest(Uri uri, String filename, String mimeType, String cookie, String userAgent, boolean publicDownloads) {
        DownloadManager.Request request = new DownloadManager.Request(uri)
            .setTitle(filename)
            .setDescription(getString(R.string.app_name))
            .setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED)
            .setAllowedOverMetered(true)
            .setAllowedOverRoaming(true);

        if (publicDownloads) {
            request.setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, filename);
        }

        if (mimeType != null && !mimeType.trim().isEmpty()) {
            request.setMimeType(mimeType);
        }

        if (cookie != null && !cookie.trim().isEmpty()) {
            request.addRequestHeader("Cookie", cookie);
        }

        if (userAgent != null && !userAgent.trim().isEmpty()) {
            request.addRequestHeader("User-Agent", userAgent);
        }

        return request;
    }

    private String cookieFor(String url) {
        return CookieManager.getInstance().getCookie(url);
    }

    private void pollBadgeCount() {
        if (webView == null) {
            return;
        }

        webView.evaluateJavascript(
            "(function(){var b=document.querySelector('.nav-badge');return b ? parseInt(b.textContent,10)||0 : 0;})()",
            value -> {
                int count = parseBadgeValue(value);
                if (lastBadgeCount >= 0 && count > lastBadgeCount) {
                    showLocalNotification(count);
                }
                lastBadgeCount = count;
            }
        );
    }

    private int parseBadgeValue(String value) {
        if (value == null) {
            return 0;
        }
        String clean = value.replace("\"", "").trim();
        try {
            return Integer.parseInt(clean);
        } catch (NumberFormatException ignored) {
            return 0;
        }
    }

    private void showLocalNotification(int count) {
        if (Build.VERSION.SDK_INT >= 33 && checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED) {
            return;
        }

        Intent intent = new Intent(this, MainActivity.class);
        intent.setFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP | Intent.FLAG_ACTIVITY_CLEAR_TOP);
        PendingIntent pendingIntent = PendingIntent.getActivity(
            this,
            0,
            intent,
            Build.VERSION.SDK_INT >= Build.VERSION_CODES.M ? PendingIntent.FLAG_IMMUTABLE : 0
        );

        android.app.Notification.Builder builder = Build.VERSION.SDK_INT >= Build.VERSION_CODES.O
            ? new android.app.Notification.Builder(this, CHANNEL_ID)
            : new android.app.Notification.Builder(this);

        builder.setSmallIcon(R.drawable.ic_notification)
            .setContentTitle(getString(R.string.app_name))
            .setContentText(count == 1 ? "Tienes 1 novedad pendiente" : "Tienes " + count + " novedades pendientes")
            .setContentIntent(pendingIntent)
            .setAutoCancel(true);

        NotificationManager manager = (NotificationManager) getSystemService(NOTIFICATION_SERVICE);
        if (manager != null) {
            manager.notify(1, builder.build());
        }
    }

    private void createNotificationChannel() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) {
            return;
        }

        NotificationChannel channel = new NotificationChannel(
            CHANNEL_ID,
            getString(R.string.notification_channel),
            NotificationManager.IMPORTANCE_DEFAULT
        );
        NotificationManager manager = (NotificationManager) getSystemService(NOTIFICATION_SERVICE);
        if (manager != null) {
            manager.createNotificationChannel(channel);
        }
    }

    private void requestNotificationPermission() {
        if (Build.VERSION.SDK_INT >= 33 && checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED) {
            requestPermissions(new String[]{Manifest.permission.POST_NOTIFICATIONS}, NOTIFICATION_PERMISSION_REQUEST);
        }
    }

    private final class UannaWebViewClient extends WebViewClient {
        @Override
        public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
            Uri uri = request.getUrl();
            String host = uri.getHost();
            if ("maximalismo.red".equalsIgnoreCase(host)) {
                return false;
            }

            Intent intent = new Intent(Intent.ACTION_VIEW, uri);
            startActivity(intent);
            return true;
        }

        @Override
        public void onPageStarted(WebView view, String url, Bitmap favicon) {
            lastBadgeCount = -1;
            super.onPageStarted(view, url, favicon);
        }

        @Override
        public void onPageFinished(WebView view, String url) {
            pollBadgeCount();
            super.onPageFinished(view, url);
        }
    }

    private final class UannaWebChromeClient extends WebChromeClient {
        @Override
        public boolean onShowFileChooser(WebView view, ValueCallback<Uri[]> callback, FileChooserParams params) {
            if (filePathCallback != null) {
                filePathCallback.onReceiveValue(null);
            }
            filePathCallback = callback;

            Intent intent = params.createIntent();
            try {
                startActivityForResult(intent, FILE_CHOOSER_REQUEST);
            } catch (Exception e) {
                filePathCallback = null;
                return false;
            }
            return true;
        }

        @Override
        public void onProgressChanged(WebView view, int newProgress) {
            progress.setProgress(newProgress);
            progress.setVisibility(newProgress >= 100 ? View.GONE : View.VISIBLE);
            super.onProgressChanged(view, newProgress);
        }
    }
}
