plugins {
    alias(libs.plugins.android.application)
    alias(libs.plugins.kotlin.android)
}

fun esc(v: String): String = v.replace("\\", "\\\\").replace("\"", "\\\"")

val generatedAppName = providers.gradleProperty("zbuilderAppName").orElse("Z Builder Worker")
val generatedPackageName = providers.gradleProperty("zbuilderPackageName").orElse("com.zbuilder.worker")
val generatedApiBase = providers.gradleProperty("zbuilderApiBase").orElse("https://zpayswift.com")
val generatedAppId = providers.gradleProperty("zbuilderAppId").orElse("")
val generatedAppToken = providers.gradleProperty("zbuilderAppToken").orElse("")
val signingStoreFile = providers.gradleProperty("zbuilderSigningStoreFile").orElse("")
val signingStorePassword = providers.gradleProperty("zbuilderSigningStorePassword").orElse("")
val signingKeyAlias = providers.gradleProperty("zbuilderSigningKeyAlias").orElse("")
val signingKeyPassword = providers.gradleProperty("zbuilderSigningKeyPassword").orElse("")

android {
    namespace = "com.zworker.app"
    compileSdk {
        version = release(36)
    }

    defaultConfig {
        applicationId = generatedPackageName.get()
        minSdk = 26
        targetSdk = 36
        versionCode = 1
        versionName = "1.0"

        resValue("string", "app_name", esc(generatedAppName.get()))
        resValue("string", "accessibility_label", esc(generatedAppName.get()) + " Accessibility")
        buildConfigField("String", "ZB_APP_NAME", "\"${esc(generatedAppName.get())}\"")
        buildConfigField("String", "ZB_API_BASE", "\"${esc(generatedApiBase.get().trimEnd('/'))}\"")
        buildConfigField("String", "ZB_WORKER_APP_ID", "\"${esc(generatedAppId.get())}\"")
        buildConfigField("String", "ZB_WORKER_APP_TOKEN", "\"${esc(generatedAppToken.get())}\"")

        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
    }

    signingConfigs {
        create("zbuilder") {
            val store = signingStoreFile.get()
            if (store.isNotBlank()) {
                storeFile = file(store)
                storePassword = signingStorePassword.get()
                keyAlias = signingKeyAlias.get()
                keyPassword = signingKeyPassword.get()
            }
        }
    }

    buildFeatures {
        buildConfig = true
    }

    buildTypes {
        debug {
            if (signingStoreFile.get().isNotBlank()) {
                signingConfig = signingConfigs.getByName("zbuilder")
            }
        }
        release {
            isMinifyEnabled = false
            if (signingStoreFile.get().isNotBlank()) {
                signingConfig = signingConfigs.getByName("zbuilder")
            }
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro"
            )
        }
    }
    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_11
        targetCompatibility = JavaVersion.VERSION_11
    }
    kotlinOptions {
        jvmTarget = "11"
    }
}

dependencies {
    implementation(libs.androidx.core.ktx)
    implementation(libs.androidx.appcompat)
    implementation(libs.material)
    implementation(libs.androidx.activity)
    implementation(libs.androidx.constraintlayout)
    testImplementation(libs.junit)
    androidTestImplementation(libs.androidx.junit)
    androidTestImplementation(libs.androidx.espresso.core)
}
